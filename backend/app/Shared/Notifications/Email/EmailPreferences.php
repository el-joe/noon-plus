<?php

namespace App\Shared\Notifications\Email;

use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Support\AdminAccess;
use App\Models\User;
use App\Shared\Notifications\Email\Models\EmailPreference;
use Illuminate\Validation\ValidationException;

/**
 * Per-user email preferences. Transactional and security mail cannot be
 * turned off; every other category follows the user's choice (or its
 * default). Admin alerts are opt-in per alert, offered only for alerts
 * whose permission the admin holds.
 */
class EmailPreferences
{
    public function __construct(private AdminAccess $access) {}

    /**
     * The preference topic an event belongs to.
     */
    public static function topic(string $event): string
    {
        $category = EmailCatalogue::category($event);

        return $category === EmailCategory::AdminAlerts ? 'admin:'.$event : $category->value;
    }

    public function enabled(User $user, string $topic): bool
    {
        $category = $this->categoryOf($topic);

        if ($category->isLocked()) {
            return true;
        }

        $stored = EmailPreference::query()->where('user_id', $user->id)->where('topic', $topic)->value('enabled');

        return $stored === null ? $category->defaultEnabled() : (bool) $stored;
    }

    public function allows(User $user, string $event): bool
    {
        return $this->enabled($user, self::topic($event));
    }

    public function set(User $user, string $topic, bool $enabled): void
    {
        $category = $this->categoryOf($topic);

        if ($category->isLocked()) {
            throw ValidationException::withMessages(['topic' => __('These emails cannot be turned off.')]);
        }

        EmailPreference::query()->updateOrCreate(['user_id' => $user->id, 'topic' => $topic], ['enabled' => $enabled]);
    }

    /**
     * The categories a user sees on their preferences page, with state.
     *
     * @param  array<int, string>|null  $only  limit to these categories (e.g. customer-facing ones)
     * @return array<int, array{topic: string, label: string, description: string, enabled: bool, locked: bool}>
     */
    public function categoriesFor(User $user, ?array $only = null): array
    {
        $categories = array_filter(EmailCategory::cases(), fn (EmailCategory $category): bool => $category !== EmailCategory::AdminAlerts
            && ($only === null || in_array($category->value, $only, true)));

        return array_values(array_map(fn (EmailCategory $category): array => [
            'topic' => $category->value,
            'label' => $category->label(),
            'description' => $category->description(),
            'enabled' => $this->enabled($user, $category->value),
            'locked' => $category->isLocked(),
        ], $categories));
    }

    /**
     * Admin alerts the user may subscribe to (they hold the permission).
     *
     * @return array<int, array{topic: string, event: string, label: string, permission: string, enabled: bool}>
     */
    public function adminAlertsFor(User $user): array
    {
        if (! $user->memberships()->where('role', MembershipRole::Admin)->where('status', 'active')->exists()) {
            return [];
        }

        $alerts = [];

        foreach (EmailCatalogue::all() as $event => $definition) {
            if ($definition['category'] !== EmailCategory::AdminAlerts) {
                continue;
            }

            $permission = AdminPermission::tryFrom((string) $definition['permission']);

            if ($permission !== null && $this->access->allows($user, $permission)) {
                $alerts[] = ['topic' => 'admin:'.$event, 'event' => $event, 'label' => $definition['label'], 'permission' => $permission->value, 'enabled' => $this->enabled($user, 'admin:'.$event)];
            }
        }

        return $alerts;
    }

    public function categoryOf(string $topic): EmailCategory
    {
        if (str_starts_with($topic, 'admin:')) {
            if (EmailCatalogue::find(substr($topic, 6)) === null) {
                throw ValidationException::withMessages(['topic' => __('Unknown email topic.')]);
            }

            return EmailCategory::AdminAlerts;
        }

        return EmailCategory::tryFrom($topic) ?? throw ValidationException::withMessages(['topic' => __('Unknown email topic.')]);
    }
}
