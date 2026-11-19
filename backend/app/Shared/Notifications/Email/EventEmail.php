<?php

namespace App\Shared\Notifications\Email;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Models\User;
use App\Shared\Notifications\Email\Attachments\EmailAttachment;
use App\Shared\Notifications\Email\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One catalogue event sent to one recipient — the single notification class
 * every email goes through ({@see Emails::send()}). Queued on the `mail`
 * queue (bell / SMS on `notifications`) after the surrounding transaction
 * commits, retried with backoff; the email log row tracks it from queued
 * to sent / failed. Subclasses (e.g. the sign-in code notification) keep
 * their own class name for existing callers and tests but share all of this.
 */
class EventEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 600, 1800];

    private ?RenderedEmail $rendered = null;

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $options  see {@see Emails::send()}
     * @param  array<int, string>|null  $channels  null = the catalogue's channels
     */
    public function __construct(
        public string $event,
        public array $variables = [],
        public array $options = [],
        public ?int $logId = null,
        public ?array $channels = null,
    ) {
        $this->afterCommit();
        $this->options['country'] ??= app(CountryContext::class)->has() ? app(CountryContext::class)->current()->code : null;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = $this->channels ?? EmailCatalogue::get($this->event)['channels'];

        return array_values(array_filter(array_map(fn (string $channel): ?string => match ($channel) {
            'mail' => 'mail',
            'bell' => $notifiable instanceof User ? BellChannel::class : null,
            'sms' => $notifiable instanceof User ? SmsChannel::class : null,
            default => null,
        }, $channels)));
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'mail', BellChannel::class => 'notifications', SmsChannel::class => 'notifications'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel !== 'mail') {
            return true;
        }

        $log = $this->logId === null ? $this->registerLazily($notifiable) : EmailLog::query()->find($this->logId);

        if ($log === null || $log->status !== 'queued') {
            return false;
        }

        if (app(EmailSuppressions::class)->blocks($log->recipient(), EmailCatalogue::category($this->event), EmailPreferences::topic($this->event))) {
            $log->update(['status' => 'suppressed', 'failure_reason' => 'Address is on the suppression list']);

            return false;
        }

        if ($this->render($notifiable) === null) {
            $log->update(['status' => 'skipped', 'failure_reason' => 'Template switched off for this language']);

            return false;
        }

        $log->increment('attempts');

        return true;
    }

    public function toMail(object $notifiable): EventMailable
    {
        $log = $this->logId === null ? null : EmailLog::query()->find($this->logId);
        $rendered = $this->render($notifiable) ?? throw new \RuntimeException("Email [{$this->event}] could not be rendered.");
        $log?->update(['subject' => mb_substr($rendered->subject, 0, 255), 'template_version' => $rendered->templateVersion]);

        return new EventMailable($rendered, $this->email($notifiable), $this->recipientName($notifiable), $this->event, $this->logId, $this->files());
    }

    /**
     * Rendered once per job in the recipient's language and the event's country.
     */
    public function render(object $notifiable): ?RenderedEmail
    {
        return $this->rendered ??= app(EmailRenderer::class)->render(
            $this->event,
            $this->locale ?? $this->recipientLocale($notifiable),
            $this->variables + ['name' => $this->recipientName($notifiable) ?? ''],
            $this->options,
            ['email' => $this->email($notifiable), 'user_id' => $notifiable instanceof User ? $notifiable->id : null, 'log_id' => $this->logId],
        );
    }

    public function failed(Throwable $exception): void
    {
        if ($this->logId !== null) {
            EmailLog::query()->whereKey($this->logId)->whereIn('status', ['queued'])->update([
                'status' => 'failed',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 1000),
                'failed_at' => now(),
            ]);
        }
    }

    public function country(): ?string
    {
        return $this->options['country'] ?? null;
    }

    public function email(object $notifiable): string
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            $route = $notifiable->routeNotificationFor('mail', $this);

            return (string) (is_array($route) ? array_key_first($route) : $route);
        }

        return (string) ($notifiable->email ?? '');
    }

    private function recipientName(object $notifiable): ?string
    {
        if (isset($this->options['name'])) {
            return (string) $this->options['name'];
        }

        if ($notifiable instanceof AnonymousNotifiable) {
            $route = $notifiable->routeNotificationFor('mail', $this);

            return is_array($route) ? (reset($route) ?: null) : null;
        }

        return $notifiable->name ?? null;
    }

    private function recipientLocale(object $notifiable): string
    {
        if (isset($this->options['force_locale'])) {
            return (string) $this->options['force_locale'];
        }

        if (! $notifiable instanceof User && isset($this->options['locale'])) {
            return (string) $this->options['locale'];
        }

        return $notifiable instanceof User ? $notifiable->preferredLocale() : (string) config('app.locale');
    }

    /**
     * Direct `->notify(new SubclassOfEventEmail(...))` callers: register the
     * log row (preferences, suppression, idempotency) at send time.
     */
    private function registerLazily(object $notifiable): ?EmailLog
    {
        $log = app(Emails::class)->register($this->event, $notifiable instanceof User ? $notifiable : null, $this->email($notifiable), $this->recipientLocale($notifiable), $this->variables, $this->options);
        $this->logId = $log?->id;

        return $log;
    }

    /**
     * @return array<int, array{data: string, name: string, mime: string}>
     */
    private function files(): array
    {
        $specs = $this->options['attachments'] ?? [];

        if ($specs === []) {
            return [];
        }

        $build = function () use ($specs): array {
            $files = [];

            foreach ($specs as [$class, $arguments]) {
                try {
                    $builder = app($class);
                    $file = $builder instanceof EmailAttachment ? $builder->build($arguments) : null;
                } catch (Throwable $exception) {
                    Log::warning('Email attachment failed', ['event' => $this->event, 'attachment' => $class, 'error' => $exception->getMessage()]);
                    $file = null;
                }

                if ($file !== null) {
                    $files[] = $file;
                }
            }

            return $files;
        };

        $country = $this->country() === null ? null : Country::query()->where('code', $this->country())->first();
        $context = app(CountryContext::class);

        return $country === null || ($context->has() && $context->current()->id === $country->id) ? $build() : $context->runAs($country, $build);
    }
}
