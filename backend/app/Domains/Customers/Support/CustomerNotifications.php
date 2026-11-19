<?php

namespace App\Domains\Customers\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Customers\Events\CustomerNotificationCreated;
use App\Domains\Customers\Models\CustomerNotification;
use App\Shared\Broadcasting\Realtime;
use App\Shared\Notifications\Push\SendPushNotification;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customer's in-app notification centre (G-W29): write one, list,
 * count unread, mark read. Rows live in the country database. Each new
 * notification is also pushed to the user's devices ({@see SendPushNotification}).
 */
class CustomerNotifications
{
    public const AUDIENCE = 'customer';

    /**
     * @param  array<string, mixed>  $data
     * @param  string  $audience  `customer`, or `panel:{key}` for a business panel bell (QC-041)
     */
    public function notify(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, array $data = [], string $audience = self::AUDIENCE): CustomerNotification
    {
        $notification = CustomerNotification::query()->create([
            'user_id' => $userId,
            'audience' => $audience,
            'type' => mb_substr($type, 0, 64),
            'title' => mb_substr($title, 0, 255),
            'body' => $body === null ? null : mb_substr($body, 0, 1000),
            'link' => $link === null ? null : mb_substr($link, 0, 255),
            'data' => $data,
        ]);

        if ($audience === self::AUDIENCE) {
            Realtime::broadcast(new CustomerNotificationCreated($userId, $notification->id, $notification->type, $data));
        }

        // The same notification as a push to the user's devices of that app (queued; G-A2 tokens).
        $country = app(CountryContext::class)->has() ? app(CountryContext::class)->current()->code : null;
        SendPushNotification::dispatch($userId, $audience, $country, $notification->title, $notification->body, $notification->link, ['type' => $notification->type, 'notification_id' => $notification->id] + $data)->afterCommit();

        return $notification;
    }

    /**
     * @return Builder<CustomerNotification>
     */
    public function forUser(int $userId, string $audience = self::AUDIENCE): Builder
    {
        return CustomerNotification::query()->where('user_id', $userId)->where('audience', $audience);
    }

    public function unreadCount(int $userId, string $audience = self::AUDIENCE): int
    {
        return $this->forUser($userId, $audience)->whereNull('read_at')->count();
    }

    /**
     * @param  array<int, int>|null  $ids  null marks every unread one
     */
    public function markRead(int $userId, ?array $ids = null, string $audience = self::AUDIENCE): int
    {
        return $this->forUser($userId, $audience)->whereNull('read_at')
            ->when($ids !== null, fn (Builder $query) => $query->whereKey($ids))
            ->update(['read_at' => now()]);
    }
}
