<?php

namespace App\Domains\Delivery\Enums;

/**
 * Shipment (SRS §8 + reschedule / return to origin, lifecycle catalogue §2.3):
 * label_created → picked_up → (in_transit →) out_for_delivery → delivered |
 * failed; failed → rescheduled → out_for_delivery; failed | rescheduled →
 * returning_to_origin → returned. Cancelled before pickup.
 */
enum ShipmentStatus: string
{
    case LabelCreated = 'label_created';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Rescheduled = 'rescheduled';
    case ReturningToOrigin = 'returning_to_origin';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::LabelCreated => [self::PickedUp, self::Cancelled],
            self::PickedUp => [self::InTransit, self::OutForDelivery, self::ReturningToOrigin],
            self::InTransit => [self::OutForDelivery, self::ReturningToOrigin],
            self::OutForDelivery => [self::Delivered, self::Failed, self::ReturningToOrigin],
            self::Failed => [self::Rescheduled, self::ReturningToOrigin],
            self::Rescheduled => [self::OutForDelivery, self::ReturningToOrigin],
            self::ReturningToOrigin => [self::Returned],
            self::Delivered, self::Returned, self::Cancelled => [],
        };
    }

    /**
     * A new assignment may be made for the shipment (first attempt or after a reschedule).
     */
    public function isAssignable(): bool
    {
        return in_array($this, [self::LabelCreated, self::Rescheduled], true);
    }
}
