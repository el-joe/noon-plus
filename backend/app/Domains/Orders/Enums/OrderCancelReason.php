<?php

namespace App\Domains\Orders\Enums;

/**
 * Why the customer cancels a shipment before it ships (QA-041). `other`
 * takes a free-text note.
 */
enum OrderCancelReason: string
{
    case ChangedMind = 'changed_mind';
    case OrderedByMistake = 'ordered_by_mistake';
    case FoundBetterPrice = 'found_better_price';
    case DeliveryTooSlow = 'delivery_too_slow';
    case WrongAddress = 'wrong_address';
    case PaymentIssue = 'payment_issue';
    case Other = 'other';
}
