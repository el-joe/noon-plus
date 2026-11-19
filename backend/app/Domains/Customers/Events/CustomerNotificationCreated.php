<?php

namespace App\Domains\Customers\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * D16: a new in-app notification for a customer on the private channel
 * `App.Models.User.{id}` (`notification.created`). The website refreshes the
 * bell and, for order notifications, the open order; without a socket it
 * keeps polling. Carries ids only: the text is fetched from the API.
 */
class CustomerNotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $broadcastQueue = 'broadcasts';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public int $userId, public int $notificationId, public string $type, public array $data = []) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificationId,
            'type' => $this->type,
            'order' => is_string($this->data['order'] ?? null) ? $this->data['order'] : null,
        ];
    }
}
