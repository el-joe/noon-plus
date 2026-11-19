<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Panels\Support\Panel;
use App\Models\User;
use App\Shared\Notifications\Email\EmailLinks;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;

/**
 * Account and security emails of the catalogue (welcome, new device,
 * password / email / two-step changes) for customers and panel users.
 * Every send goes through {@see Emails::send()}, so a failure never breaks
 * the sign-in or account change that triggered it.
 */
class SecurityEmails
{
    /**
     * Sign-in channels of the apps that belong to a panel.
     */
    private const APP_PANELS = ['ops_app' => 'admin', 'delivery_app' => 'delivery'];

    public function __construct(private Emails $emails, private EmailLinks $links) {}

    /**
     * `account.welcome` — once per account, on the first sign-in of a newly opened customer account.
     */
    public function welcome(User $user): void
    {
        $this->emails->send('account.welcome', $user, [], [
            'entity' => $user,
            'url' => $this->links->storefront('', $user->preferredLocale()),
        ]);
    }

    /**
     * `auth.new_device` — a successful sign-in from a browser / network the
     * account never signed in from before (the very first sign-in is not "new").
     */
    public function newDevice(LoginEvent $event, User $user): void
    {
        $earlier = LoginEvent::query()
            ->where('user_id', $user->id)
            ->where('outcome', LoginOutcome::Succeeded)
            ->whereKeyNot($event->id);

        if (! (clone $earlier)->exists()) {
            return;
        }

        $seen = (clone $earlier)
            ->where('user_agent', (string) $event->user_agent)
            ->where('ip_address', $event->ip_address)
            ->exists();

        if ($seen) {
            return;
        }

        $panel = $this->panelOfChannel((string) $event->channel);

        $this->emails->send('auth.new_device', $user, [
            'device' => self::device((string) $event->user_agent),
            'ip_address' => (string) ($event->ip_address ?? ''),
            'time' => EmailValue::date($event->created_at ?? now(), time: true),
        ], array_filter([
            'entity' => $event,
            'panel' => $panel,
            'url' => $panel === null ? $this->links->storefront('account/security', $user->preferredLocale()) : $this->links->panel($panel, 'account/security'),
        ]));
    }

    /**
     * `auth.password_changed` — `$panel` is the panel key when the change happened in a business panel.
     */
    public function passwordChanged(User $user, ?string $panel = null): void
    {
        $this->emails->send('auth.password_changed', $user, [
            'time' => EmailValue::date(now(), time: true),
        ], array_filter(['entity' => $user, 'once' => false, 'panel' => $panel], fn ($value): bool => $value !== null));
    }

    /**
     * `auth.email_changed` — to the new address (the account) and the old one.
     */
    public function emailChanged(User $user, string $oldEmail, string $newEmail): void
    {
        $this->emails->send('auth.email_changed', [$user, $oldEmail], [
            'old_email' => $oldEmail,
            'new_email' => $newEmail,
            'time' => EmailValue::date(now(), time: true),
        ], [
            'entity' => $user,
            'once' => false,
            'locale' => $user->preferredLocale(),
            'name' => $user->name,
        ]);
    }

    /**
     * `auth.two_factor_changed` — two-step sign-in turned on or off.
     */
    public function twoFactorChanged(User $user): void
    {
        $this->emails->send('auth.two_factor_changed', $user, [
            'state' => EmailValue::t($user->two_factor_enabled ? 'on' : 'off'),
            'time' => EmailValue::date(now(), time: true),
        ], array_filter(['entity' => $user, 'once' => false, 'panel' => $this->panelOf($user)], fn ($value): bool => $value !== null));
    }

    /**
     * A short device summary from a user agent, e.g. "Chrome on Windows".
     *
     * @return string|array{t: string, r: array<string, scalar|null>}
     */
    public static function device(string $userAgent): string|array
    {
        $browser = match (true) {
            $userAgent === '' => null,
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            str_contains($userAgent, 'Dart/') || str_contains($userAgent, 'okhttp') || str_contains($userAgent, 'CFNetwork') => 'Nawy app',
            default => null,
        };

        $system = match (true) {
            $userAgent === '' => null,
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') || str_contains($userAgent, 'iOS') || str_contains($userAgent, 'CFNetwork') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $system !== null => EmailValue::t(':browser on :system', ['browser' => $browser, 'system' => $system]),
            $browser !== null => $browser,
            $system !== null => $system,
            default => EmailValue::t('Unknown device'),
        };
    }

    private function panelOfChannel(string $channel): ?string
    {
        $key = self::APP_PANELS[$channel] ?? $channel;

        return config("nawy.panels.{$key}") === null ? null : $key;
    }

    /**
     * The first business panel the user can open (null for customers).
     */
    private function panelOf(User $user): ?string
    {
        foreach (array_keys((array) config('nawy.panels')) as $key) {
            if (Panel::find($key)->allows($user)) {
                return $key;
            }
        }

        return null;
    }
}
