<?php

namespace App\Shared\Notifications\Email;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Support\AdminAccess;
use App\Models\User;
use App\Shared\Notifications\Email\Models\EmailLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * The single email dispatcher: every catalogue event is sent through
 * {@see self::send()} (or {@see self::alertAdmins()}). It resolves the
 * recipients, applies preferences and the suppression list, de-duplicates
 * (same event + entity + recipient is sent once), writes the email log row
 * and queues one {@see EventEmail} per recipient.
 *
 * Options:
 *  - `entity` Model|string — what the email is about (log + idempotency key)
 *  - `key` string — extra idempotency discriminator (e.g. `7d` for a reminder)
 *  - `once` bool (default true when `entity` or `key` is given)
 *  - `url` string — the button link
 *  - `blocks` array — structured content ({@see EmailRenderer})
 *  - `attachments` array<int, array{0: class-string<Attachments\EmailAttachment>, 1: array}>
 *  - `locale` 'en'|'ar' — for guests (users get their own language)
 *  - `name` string — the recipient's name for guests
 *  - `country` string — country code (default: the current country)
 *  - `panel` string — panel key for links (default: the actor's panel)
 *  - `channels` array — override the catalogue channels
 *  - `bell_link` string — in-app link for the bell channel
 *  - `force_locale` 'en'|'ar' and `test` bool — template editor test sends
 *    (any language; preferences ignored, suppression list still applies)
 *  - `template_override` array{subject, body} — unsaved editor text
 */
class Emails
{
    public function __construct(
        private EmailPreferences $preferences,
        private EmailSuppressions $suppressions,
        private CountryContext $countryContext,
        private AdminAccess $access,
    ) {}

    /**
     * @param  User|int|string|iterable<User|int|string>|null  $to
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $options
     * @return Collection<int, EmailLog> the queued log rows (duplicates / suppressed ones are left out)
     */
    public function send(string $event, mixed $to, array $variables = [], array $options = []): Collection
    {
        EmailCatalogue::get($event);
        $options = $this->normalize($options);

        // Inside a transaction (e.g. a lifecycle move): send only once it commits, so a rollback sends nothing.
        $transactions = app()->bound('db.transactions') ? app('db.transactions') : null;

        if ($transactions !== null && $transactions->callbackApplicableTransactions()->isNotEmpty()) {
            $transactions->addCallback(fn () => $this->sendNow($event, $to, $variables, $options));

            return collect();
        }

        return $this->sendNow($event, $to, $variables, $options);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $options
     * @return Collection<int, EmailLog>
     */
    private function sendNow(string $event, mixed $to, array $variables, array $options): Collection
    {
        $sent = collect();

        foreach ($this->recipients($to) as $recipient) {
            try {
                $log = $this->dispatch($event, $recipient, $variables, $options);
            } catch (InvalidArgumentException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                // An email must never break the business flow that triggered it.
                Log::error('Email dispatch failed', ['event' => $event, 'error' => $exception->getMessage()]);
                report($exception);
                $log = null;
            }

            if ($log !== null) {
                $sent->push($log);
            }
        }

        return $sent;
    }

    /**
     * Ops alert to the admins of the current country (super admins included)
     * who hold the event's permission and opted in to this alert.
     *
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $options
     * @return Collection<int, EmailLog>
     */
    public function alertAdmins(string $event, array $variables = [], array $options = []): Collection
    {
        $definition = EmailCatalogue::get($event);
        $permission = AdminPermission::from((string) $definition['permission']);
        $countryId = $this->countryContext->has() ? $this->countryContext->current()->id : null;
        $topic = EmailPreferences::topic($event);

        $admins = User::query()
            ->whereHas('memberships', fn ($query) => $query->where('role', MembershipRole::Admin)->where('status', 'active')
                ->where(fn ($scope) => $scope->whereNull('country_id')->when($countryId !== null, fn ($q) => $q->orWhere('country_id', $countryId))))
            ->whereIn('id', fn ($query) => $query->select('user_id')->from('email_preferences')->where('topic', $topic)->where('enabled', true))
            ->orderBy('id')->limit(100)->get()
            ->filter(fn (User $admin): bool => $this->access->allows($admin, $permission));

        return $this->send($event, $admins, $variables, $options + ['panel' => 'admin']);
    }

    /**
     * Writes the log row for one recipient after the preference, suppression
     * and idempotency checks. Returns null for a duplicate; a row with status
     * `suppressed` when the recipient must not get it.
     *
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $options
     */
    public function register(string $event, ?User $user, string $email, string $locale, array $variables, array $options): ?EmailLog
    {
        $definition = EmailCatalogue::get($event);
        /** @var EmailCategory $category */
        $category = $definition['category'];
        $topic = EmailPreferences::topic($event);
        $hash = EmailAddress::hash($email);
        $key = $this->idempotencyKey($event, $hash, $options);

        if ($key !== null && EmailLog::query()->where('idempotency_key', $key)->exists()) {
            return null;
        }

        $blocked = match (true) {
            $user !== null && empty($options['test']) && ! $this->preferences->enabled($user, $topic) => 'Recipient turned this email off',
            $this->suppressions->blocks($email, $category, $topic) => 'Address is on the suppression list',
            default => null,
        };

        try {
            return EmailLog::query()->create([
                'event' => $event,
                'category' => $category->value,
                'actor' => $definition['actor'],
                'user_id' => $user?->id,
                'recipient_hash' => $hash,
                'recipient_masked' => EmailAddress::mask($email),
                'recipient_encrypted' => EmailAddress::normalize($email),
                'locale' => $locale,
                'country_code' => $options['country'] ?? null,
                'entity_type' => $options['entity_type'] ?? null,
                'entity_id' => $options['entity_id'] ?? null,
                'idempotency_key' => $blocked === null ? $key : null,
                'status' => $blocked === null ? 'queued' : 'suppressed',
                'failure_reason' => $blocked,
                'payload' => $definition['sensitive'] ? null : ['variables' => $variables, 'options' => $options],
                'queued_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Send a logged email again (Admin → Email log): a new log row, no idempotency.
     */
    public function resend(EmailLog $log): ?EmailLog
    {
        if (! $log->canResend()) {
            return null;
        }

        $options = ['once' => false] + ($log->payload['options'] ?? []);
        unset($options['key']);
        $user = $log->user_id === null ? null : User::query()->find($log->user_id);
        $recipient = $user !== null && EmailAddress::normalize((string) $user->email) === $log->recipient() ? $user : $log->recipient();

        $sent = $this->send($log->event, $recipient, $log->payload['variables'] ?? [], $options);
        $sent->first()?->update(['resent_from_id' => $log->id]);

        return $sent->first();
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $options
     */
    private function dispatch(string $event, array $recipient, array $variables, array $options): ?EmailLog
    {
        /** @var User|null $user */
        $user = $recipient['user'];
        $locale = $options['force_locale'] ?? $user?->preferredLocale() ?? ($options['locale'] ?? config('app.locale'));
        $log = $this->register($event, $user, $recipient['email'], $locale, $variables, $options);

        if ($log === null) {
            return null;
        }

        $channels = $options['channels'] ?? EmailCatalogue::get($event)['channels'];

        if ($log->status !== 'queued') {
            // Opted out: nothing at all. Suppressed address: the in-app channels still go out.
            $channels = $log->failure_reason === 'Recipient turned this email off' ? [] : array_values(array_diff($channels, ['mail']));
        }

        if ($channels !== []) {
            $notification = new EventEmail($event, $variables, $options + ['name' => $recipient['name']], $log->id, $channels);
            $user !== null ? $user->notify($notification) : Notification::route('mail', [$recipient['email'] => $recipient['name']])->notify($notification->locale($locale));
        }

        return $log->status === 'queued' ? $log : null;
    }

    /**
     * @return array<int, array{user: User|null, email: string, name: string|null}>
     */
    private function recipients(mixed $to): array
    {
        $items = is_iterable($to) && ! is_string($to) ? $to : [$to];
        $resolved = [];

        foreach ($items as $item) {
            $user = match (true) {
                $item instanceof User => $item,
                is_int($item) => User::query()->find($item),
                is_string($item) && $item !== '' => User::query()->where('email', EmailAddress::normalize($item))->first(),
                default => null,
            };
            $email = $item instanceof User || is_int($item) ? (string) $user?->email : (string) $item;

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $resolved[EmailAddress::normalize($email)] = ['user' => $user, 'email' => $email, 'name' => $user?->name];
        }

        return array_values($resolved);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalize(array $options): array
    {
        $entity = $options['entity'] ?? null;
        unset($options['entity']);

        if ($entity instanceof Model) {
            $options['entity_type'] = class_basename($entity);
            $options['entity_id'] = (string) $entity->getKey();
        } elseif (is_string($entity) && $entity !== '') {
            [$type, $id] = array_pad(explode(':', $entity, 2), 2, null);
            $options['entity_type'] = $id === null ? null : $type;
            $options['entity_id'] = $id ?? $type;
        }

        $options['country'] ??= $this->countryContext->has() ? $this->countryContext->current()->code : null;

        return $options;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function idempotencyKey(string $event, string $hash, array $options): ?string
    {
        $scoped = isset($options['entity_id']) || isset($options['key']);

        if (($options['once'] ?? $scoped) === false || ! $scoped) {
            return null;
        }

        return hash('sha256', implode('|', [$event, $options['country'] ?? '', $options['entity_type'] ?? '', $options['entity_id'] ?? '', $options['key'] ?? '', $hash]));
    }
}
