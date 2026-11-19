<?php

namespace App\Shared\Notifications\Email;

use App\Shared\Notifications\Email\Models\EmailSuppression;

/**
 * The suppression list: hard bounces and complaints from the provider
 * webhook, guest unsubscribes and manual blocks from Admin → Email log.
 */
class EmailSuppressions
{
    /**
     * Whether mail of this category / topic to the address must not go out.
     */
    public function blocks(string $email, EmailCategory $category, string $topic): bool
    {
        $rows = EmailSuppression::query()->where('email_hash', EmailAddress::hash($email))->whereIn('topic', ['*', $topic])->get(['topic', 'reason']);

        foreach ($rows as $row) {
            if (in_array($row->reason, EmailSuppression::BLOCKS_ALL, true)) {
                return true;
            }

            if (! $category->isLocked()) {
                return true;
            }
        }

        return false;
    }

    public function suppress(string $email, string $reason, string $topic = '*', ?string $source = null, ?string $detail = null, ?int $by = null): EmailSuppression
    {
        return EmailSuppression::query()->updateOrCreate(
            ['email_hash' => EmailAddress::hash($email), 'topic' => $topic],
            ['email_masked' => EmailAddress::mask($email), 'reason' => $reason, 'source' => $source, 'detail' => $detail === null ? null : mb_substr($detail, 0, 1000), 'created_by' => $by],
        );
    }

    public function suppressHash(string $hash, string $masked, string $reason, string $topic = '*', ?string $source = null): EmailSuppression
    {
        return EmailSuppression::query()->updateOrCreate(['email_hash' => $hash, 'topic' => $topic], ['email_masked' => $masked, 'reason' => $reason, 'source' => $source]);
    }

    public function lift(string $hash, string $topic = '*'): int
    {
        return EmailSuppression::query()->where('email_hash', $hash)->where('topic', $topic)->delete();
    }
}
