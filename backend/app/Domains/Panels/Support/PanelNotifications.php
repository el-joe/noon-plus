<?php

namespace App\Domains\Panels\Support;

use App\Domains\Customers\Models\CustomerNotification;
use App\Domains\Customers\Support\CustomerNotifications;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The notification bell of every business panel (QC-041). Rows share the
 * country notification table with audience `panel:{key}`; links are paths
 * on that panel's host.
 *
 * @see CustomerNotifications
 */
class PanelNotifications
{
    public function __construct(private CustomerNotifications $notifications) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function notify(int $userId, string $panelKey, string $type, string $title, ?string $body = null, ?string $link = null, array $data = []): CustomerNotification
    {
        return $this->notifications->notify($userId, $type, $title, $body, $link, $data, self::audience($panelKey));
    }

    /**
     * @return Builder<CustomerNotification>
     */
    public function forUser(int $userId, string $panelKey): Builder
    {
        return $this->notifications->forUser($userId, self::audience($panelKey));
    }

    /**
     * Unread count for the bell; 0 when no country is selected yet.
     */
    public function unreadCount(int $userId, string $panelKey): int
    {
        try {
            return $this->notifications->unreadCount($userId, self::audience($panelKey));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<int, int>|null  $ids
     */
    public function markRead(int $userId, string $panelKey, ?array $ids = null): int
    {
        return $this->notifications->markRead($userId, $ids, self::audience($panelKey));
    }

    public static function audience(string $panelKey): string
    {
        return 'panel:'.$panelKey;
    }
}
