<?php

namespace App\Domains\Support\Enums;

/**
 * What a ticket is about (SRS §15 Support tickets).
 */
enum TicketCategory: string
{
    case Order = 'order';
    case Payment = 'payment';
    case Delivery = 'delivery';
    case ReturnsRefunds = 'returns_refunds';
    case Account = 'account';
    case Product = 'product';
    case Technical = 'technical';
    case Selling = 'selling';
    case Payouts = 'payouts';
    case Other = 'other';

    /**
     * Categories offered to a requester type: business partners also get
     * selling and payouts.
     *
     * @return array<int, self>
     */
    public static function for(TicketRequesterType $type): array
    {
        return $type === TicketRequesterType::Customer
            ? [self::Order, self::Payment, self::Delivery, self::ReturnsRefunds, self::Account, self::Product, self::Technical, self::Other]
            : self::cases();
    }
}
