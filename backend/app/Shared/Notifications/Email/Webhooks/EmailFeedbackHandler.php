<?php

namespace App\Shared\Notifications\Email\Webhooks;

use App\Shared\Notifications\Email\EmailAddress;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailSuppressions;
use App\Shared\Notifications\Email\Models\EmailLog;

/**
 * Applies provider feedback: updates the matching email log row (by log id
 * metadata, else provider message id), suppresses the address on a hard
 * bounce or a complaint, and alerts opted-in admins on complaints.
 */
class EmailFeedbackHandler
{
    public function __construct(private EmailSuppressions $suppressions, private Emails $emails) {}

    public function handle(EmailFeedback $feedback, string $provider): void
    {
        $log = $this->findLog($feedback);
        $email = $feedback->email ?? $log?->recipient();

        match ($feedback->type) {
            'delivered' => $log?->update(['status' => in_array($log->status, ['queued', 'sent'], true) ? 'sent' : $log->status, 'sent_at' => $log->sent_at ?? now()]),
            'open' => $log?->update(['opened_at' => $log->opened_at ?? now()]),
            'bounce' => $log?->update(['status' => 'bounced', 'bounced_at' => now(), 'failure_reason' => $this->reason(($feedback->permanent ? 'Hard bounce' : 'Soft bounce'), $feedback->reason)]),
            'complaint' => $log?->update(['status' => 'complained', 'bounced_at' => now(), 'failure_reason' => $this->reason('Spam complaint', $feedback->reason)]),
            default => null,
        };

        if ($email === null || $email === '') {
            return;
        }

        if ($feedback->type === 'bounce' && $feedback->permanent) {
            $this->suppressions->suppress($email, 'hard_bounce', '*', $provider, $feedback->reason);
        }

        if ($feedback->type === 'complaint') {
            $this->suppressions->suppress($email, 'complaint', '*', $provider, $feedback->reason);
            $this->emails->alertAdmins('admin.email_bounces', ['event' => $log?->event ?? '—', 'type' => 'complaint', 'recipient' => EmailAddress::mask($email)], ['entity' => 'EmailLog:'.($log?->id ?? EmailAddress::hash($email))]);
        }
    }

    private function findLog(EmailFeedback $feedback): ?EmailLog
    {
        if ($feedback->logId !== null) {
            $log = EmailLog::query()->find($feedback->logId);

            if ($log !== null) {
                return $log;
            }
        }

        return $feedback->messageId === null ? null : EmailLog::query()->where('provider_message_id', trim($feedback->messageId, '<> '))->latest('id')->first();
    }

    private function reason(string $kind, ?string $detail): string
    {
        return mb_substr($kind.($detail === null || $detail === '' ? '' : ': '.$detail), 0, 1000);
    }
}
