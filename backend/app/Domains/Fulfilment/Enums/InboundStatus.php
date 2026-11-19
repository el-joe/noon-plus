<?php

namespace App\Domains\Fulfilment\Enums;

/**
 * FBN inbound shipment (ASN) lifecycle:
 * draft → submitted → in_transit → arrived → receiving → received → closed;
 * cancelled from draft, submitted or in_transit (nothing received yet).
 */
enum InboundStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InTransit = 'in_transit';
    case Arrived = 'arrived';
    case Receiving = 'receiving';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::InTransit, self::Arrived, self::Cancelled],
            self::InTransit => [self::Arrived, self::Cancelled],
            self::Arrived => [self::Receiving],
            self::Receiving => [self::Received],
            self::Received => [self::Closed],
            self::Closed, self::Cancelled => [],
        };
    }

    /**
     * Still on its way to (or waiting at) the warehouse door.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::InTransit, self::Arrived, self::Receiving], true);
    }

    /**
     * Labels can be printed once the plan has its reference.
     */
    public function hasLabels(): bool
    {
        return ! in_array($this, [self::Draft, self::Cancelled], true);
    }
}
