<?php

namespace App\Domains\Customers\Support;

use App\Domains\Customers\Models\CustomerNotification;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customer's in-app notification centre (G-W29): write one, list,
 * count unread, mark read. Rows live in the country database.
 */
class CustomerNotifications
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function notify(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, array $data = []): CustomerNotification
    {
        return CustomerNotification::query()->create([
            'user_id' => $userId,
            'type' => mb_substr($type, 0, 64),
            'title' => mb_substr($title, 0, 255),
            'body' => $body === null ? null : mb_substr($body, 0, 1000),
            'link' => $link === null ? null : mb_substr($link, 0, 255),
            'data' => $data,
        ]);
    }

    /**
     * @return Builder<CustomerNotification>
     */
    public function forUser(int $userId): Builder
    {
        return CustomerNotification::query()->where('user_id', $userId);
    }

    public function unreadCount(int $userId): int
    {
        return $this->forUser($userId)->whereNull('read_at')->count();
    }

    /**
     * @param  array<int, int>|null  $ids  null marks every unread one
     */
    public function markRead(int $userId, ?array $ids = null): int
    {
        return $this->forUser($userId)->whereNull('read_at')
            ->when($ids !== null, fn (Builder $query) => $query->whereKey($ids))
            ->update(['read_at' => now()]);
    }
}
