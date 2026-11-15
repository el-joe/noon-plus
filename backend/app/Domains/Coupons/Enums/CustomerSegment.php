<?php

namespace App\Domains\Coupons\Enums;

/**
 * Which customers may use a coupon (QC-014). Segments other than everyone
 * need a signed-in customer.
 */
enum CustomerSegment: string
{
    case Everyone = 'everyone';
    case NewCustomers = 'new_customers';
    case ReturningCustomers = 'returning_customers';
    case NawyOneMembers = 'nawy_one_members';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => __('Everyone'),
            self::NewCustomers => __('New customers (no order yet)'),
            self::ReturningCustomers => __('Returning customers'),
            self::NawyOneMembers => __('Nawy One members'),
        };
    }
}
