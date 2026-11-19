<?php

namespace App\Shared\Notifications\Email;

use App\Models\User;
use App\Shared\Notifications\Email\Models\EmailLog;
use App\Shared\Notifications\Email\Models\EmailSuppression;

/**
 * One-click unsubscribe from a signed token: a user's preference is turned
 * off for that topic; a guest address goes on the suppression list for it.
 * Transactional and security topics cannot be unsubscribed.
 */
class Unsubscribes
{
    public function __construct(private UnsubscribeTokens $tokens, private EmailPreferences $preferences, private EmailSuppressions $suppressions) {}

    /**
     * @return array{topic: string, label: string, email: string|null, subscribed: bool}|null
     */
    public function describe(string $token): ?array
    {
        $data = $this->tokens->read($token);

        if ($data === null || ! $this->isOptional($data['topic'])) {
            return null;
        }

        $category = $this->preferences->categoryOf($data['topic']);
        $user = $this->user($data);
        $label = $category === EmailCategory::AdminAlerts ? (string) (EmailCatalogue::find(substr($data['topic'], 6))['label'] ?? $category->label()) : $category->label();

        return [
            'topic' => $data['topic'],
            'label' => $label,
            'email' => $data['log_id'] === null ? null : EmailLog::query()->whereKey($data['log_id'])->value('recipient_masked'),
            'subscribed' => $user !== null
                ? $this->preferences->enabled($user, $data['topic'])
                : ! EmailSuppression::query()->where('email_hash', $data['hash'])->where('topic', $data['topic'])->exists(),
        ];
    }

    public function unsubscribe(string $token): bool
    {
        return $this->apply($token, false);
    }

    public function resubscribe(string $token): bool
    {
        return $this->apply($token, true);
    }

    private function apply(string $token, bool $subscribed): bool
    {
        $data = $this->tokens->read($token);

        if ($data === null || ! $this->isOptional($data['topic'])) {
            return false;
        }

        $user = $this->user($data);

        if ($user !== null) {
            $this->preferences->set($user, $data['topic'], $subscribed);

            return true;
        }

        if ($subscribed) {
            $this->suppressions->lift($data['hash'], $data['topic']);
        } else {
            $masked = $data['log_id'] === null ? '—' : (string) EmailLog::query()->whereKey($data['log_id'])->value('recipient_masked');
            $this->suppressions->suppressHash($data['hash'], $masked, 'unsubscribed', $data['topic'], 'one-click');
        }

        return true;
    }

    private function isOptional(string $topic): bool
    {
        try {
            return ! $this->preferences->categoryOf($topic)->isLocked();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The token's user, only while the account still has the address it was sent to.
     *
     * @param  array{hash: string, user_id: int|null}  $data
     */
    private function user(array $data): ?User
    {
        $user = $data['user_id'] === null ? null : User::query()->find($data['user_id']);

        return $user !== null && EmailAddress::hash((string) $user->email) === $data['hash'] ? $user : null;
    }
}
