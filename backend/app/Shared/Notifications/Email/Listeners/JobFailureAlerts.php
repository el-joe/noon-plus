<?php

namespace App\Shared\Notifications\Email\Listeners;

use App\Domains\Panels\Support\Panel;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EventEmail;
use App\Shared\Notifications\Email\EventMailable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

/**
 * `admin.job_failed`: a queue job or scheduled task failed — one alert per
 * job class per hour. Failures of the email jobs themselves never alert
 * (the alert is an email: no loops).
 */
class JobFailureAlerts
{
    public function __construct(private Emails $emails) {}

    public function jobFailed(JobFailed $event): void
    {
        $name = (string) $event->job->resolveName();

        if ($event->job->getQueue() === 'mail' || $this->isEmailJob($name)) {
            return;
        }

        $this->alert($name, (string) $event->job->getQueue(), $event->exception);
    }

    public function taskFailed(ScheduledTaskFailed $event): void
    {
        $name = trim((string) ($event->task->description ?: $event->task->command));
        $this->alert($name === '' ? 'Scheduled task' : $name, 'schedule', $event->exception);
    }

    private function alert(string $job, string $queue, Throwable $exception): void
    {
        try {
            $this->emails->alertAdmins('admin.job_failed', [
                'job' => mb_substr($job, 0, 200),
                'queue' => $queue,
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ], [
                'key' => 'job-failed:'.$job.':'.now()->format('Y-m-d-H'),
                'url' => Panel::find('admin')->url('/health'),
            ]);
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    private function isEmailJob(string $name): bool
    {
        foreach ([EventEmail::class, EventMailable::class, SendQueuedNotifications::class, SendQueuedMailable::class] as $class) {
            if ($name === $class || is_a($name, $class, true)) {
                return true;
            }
        }

        return false;
    }
}
