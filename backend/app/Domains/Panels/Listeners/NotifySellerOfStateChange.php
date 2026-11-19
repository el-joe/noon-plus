<?php

namespace App\Domains\Panels\Listeners;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Support\PanelNotifications;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\StateMachine\StateMoved;

/**
 * QC-041: Seller Center bell — the store owner hears when a shipment is
 * confirmed (ready to fulfil) or cancelled by someone else. Returns and
 * disputes notify from their own flows ({@see PanelNotifications}).
 */
class NotifySellerOfStateChange
{
    public function __construct(private PanelNotifications $notifications) {}

    public function handle(StateMoved $event): void
    {
        if (! $event->subject instanceof SubOrder || ! in_array($event->to->value, ['confirmed', 'cancelled'], true) || $event->actorType === ActorType::Vendor) {
            return;
        }

        $subOrder = $event->subject;
        $ownerId = Vendor::query()->whereKey($subOrder->vendor_id)->value('owner_user_id');

        if ($ownerId === null) {
            return;
        }

        $this->notifications->notify((int) $ownerId, 'partner', 'order.'.$event->to->value,
            $event->to->value === 'confirmed'
                ? __('New order :number to fulfil', ['number' => $subOrder->number])
                : __('Order :number was cancelled', ['number' => $subOrder->number]),
            $event->reason, '/orders/'.$subOrder->id, ['shipment' => $subOrder->number]);
    }

    /**
     * For flows without a state move event (return requested, dispute opened).
     */
    public static function notifyVendor(int $vendorId, string $type, string $title, ?string $body, string $link): void
    {
        $ownerId = Vendor::query()->whereKey($vendorId)->value('owner_user_id');

        if ($ownerId !== null) {
            app(PanelNotifications::class)->notify((int) $ownerId, 'partner', $type, $title, $body, $link);
        }
    }
}
