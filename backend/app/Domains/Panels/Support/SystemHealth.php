<?php

namespace App\Domains\Panels\Support;

use App\Domains\Countries\Models\Country;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * System health checks for Admin → Platform → System health (QC-044,
 * Figma 99:3364): Horizon and queue backlog, failed jobs, the scheduler's
 * last runs (recorded from schedule events), per-country database
 * connectivity, mail and storage. Every probe catches its own failure so
 * one broken dependency never breaks the page.
 */
class SystemHealth
{
    private const SCHEDULE_CACHE = 'nawy:health:schedule:';

    /**
     * @return array{status: string, supervisors: int}
     */
    public function horizon(): array
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();

            return [
                'status' => $masters === [] ? 'inactive' : (collect($masters)->contains(fn ($master): bool => ($master->status ?? null) === 'paused') ? 'paused' : 'running'),
                'supervisors' => count($masters),
            ];
        } catch (Throwable) {
            return ['status' => 'unknown', 'supervisors' => 0];
        }
    }

    /**
     * @return array<int, array{queue: string, size: int|null}>
     */
    public function queues(): array
    {
        $names = collect(config('horizon.defaults', []))->pluck('queue')->flatten()->filter()->unique()->values()->all() ?: ['default'];

        return array_map(function (string $queue): array {
            try {
                return ['queue' => $queue, 'size' => Queue::size($queue)];
            } catch (Throwable) {
                return ['queue' => $queue, 'size' => null];
            }
        }, $names);
    }

    /**
     * @return array{total: int, last_day: int, latest: Collection<int, object>}
     */
    public function failedJobs(): array
    {
        try {
            $table = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'));

            return [
                'total' => (clone $table)->count(),
                'last_day' => (clone $table)->where('failed_at', '>=', now()->subDay())->count(),
                'latest' => (clone $table)->latest('failed_at')->limit(20)->get(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
                    ->map(function (object $job): object {
                        $payload = json_decode((string) $job->payload, true);
                        $job->name = is_array($payload) ? (string) ($payload['displayName'] ?? 'Job') : 'Job';
                        $job->error = Str::limit(strtok((string) $job->exception, "\n") ?: '', 140);

                        return $job;
                    }),
            ];
        } catch (Throwable) {
            return ['total' => 0, 'last_day' => 0, 'latest' => collect()];
        }
    }

    /**
     * @return array<int, array{command: string, expression: string, next_due: Carbon|null, last_run: array{at: string, ok: bool, runtime: float|null}|null}>
     */
    public function schedule(): array
    {
        if (app(Schedule::class)->events() === []) {
            try {
                app(ConsoleKernel::class)->all();
            } catch (Throwable) {
                // The schedule stays empty if the console cannot boot here.
            }
        }

        return collect(app(Schedule::class)->events())->map(function (ScheduledEvent $event): array {
            $command = $this->commandName($event);

            try {
                $next = Carbon::instance($event->nextRunDate());
            } catch (Throwable) {
                $next = null;
            }

            return [
                'command' => $command,
                'expression' => $event->expression,
                'next_due' => $next,
                'last_run' => Cache::get(self::SCHEDULE_CACHE.sha1($command)),
            ];
        })->values()->all();
    }

    /**
     * Listener for the scheduler's finished / failed events.
     */
    public function recordScheduledRun(ScheduledTaskFinished|ScheduledTaskFailed $event): void
    {
        try {
            Cache::forever(self::SCHEDULE_CACHE.sha1($this->commandName($event->task)), [
                'at' => now()->toIso8601String(),
                'ok' => $event instanceof ScheduledTaskFinished && ($event->task->exitCode ?? 0) === 0,
                'runtime' => $event instanceof ScheduledTaskFinished ? round($event->runtime, 2) : null,
            ]);
        } catch (Throwable) {
            // The health page must never break the scheduler.
        }
    }

    /**
     * @return array<int, array{code: string, name: string, ok: bool, ms: float|null, error: string|null}>
     */
    public function countryDatabases(): array
    {
        return Country::query()->orderBy('id')->get()->map(function (Country $country): array {
            $started = microtime(true);

            try {
                DB::connection($country->connectionName())->select('select 1');

                return ['code' => $country->code, 'name' => $country->name_en, 'ok' => true, 'ms' => round((microtime(true) - $started) * 1000, 1), 'error' => null];
            } catch (Throwable $error) {
                return ['code' => $country->code, 'name' => $country->name_en, 'ok' => false, 'ms' => null, 'error' => Str::limit($error->getMessage(), 120)];
            }
        })->all();
    }

    /**
     * @return array{mailer: string, from: string|null, ok: bool, note: string}
     */
    public function mail(): array
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        $delivers = ! in_array($transport, ['log', 'array'], true);

        return [
            'mailer' => $mailer,
            'from' => config('mail.from.address'),
            'ok' => filled(config('mail.from.address')),
            'note' => $delivers ? __('Sends through :transport.', ['transport' => $transport]) : __('Mail is written to the :transport driver, not delivered.', ['transport' => $transport]),
        ];
    }

    /**
     * Write, read and delete a probe file on each disk.
     *
     * @return array<int, array{disk: string, ok: bool, error: string|null}>
     */
    public function storage(): array
    {
        return array_map(function (string $disk): array {
            $path = '.health/'.Str::uuid().'.txt';

            try {
                Storage::disk($disk)->put($path, 'ok');
                $ok = Storage::disk($disk)->get($path) === 'ok';
                Storage::disk($disk)->delete($path);

                return ['disk' => $disk, 'ok' => $ok, 'error' => null];
            } catch (Throwable $error) {
                return ['disk' => $disk, 'ok' => false, 'error' => Str::limit($error->getMessage(), 120)];
            }
        }, ['local', 'public']);
    }

    /**
     * @return array{store: string, ok: bool}
     */
    public function cache(): array
    {
        try {
            $key = 'nawy:health:probe';
            Cache::put($key, 'ok', 10);

            return ['store' => (string) config('cache.default'), 'ok' => Cache::get($key) === 'ok'];
        } catch (Throwable) {
            return ['store' => (string) config('cache.default'), 'ok' => false];
        }
    }

    private function commandName(ScheduledEvent $event): string
    {
        $command = (string) ($event->command ?? $event->description ?? 'closure');

        return trim(Str::after($command, "'artisan'")) ?: ($event->description ?? 'closure');
    }
}
