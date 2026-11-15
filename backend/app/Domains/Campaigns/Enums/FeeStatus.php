<?php

namespace App\Domains\Campaigns\Enums;

/**
 * Campaign fee (SRS §11.10): quoted (snapshot at creation) → charged at
 * go-live (a line on the owner's next statement) | voided on reject/cancel
 * before go-live; charged → refunded per `campaign_fee_refund_policy`.
 * `none` = platform and peer campaigns pay no fee.
 */
enum FeeStatus: string
{
    case None = 'none';
    case Quoted = 'quoted';
    case Charged = 'charged';
    case Voided = 'voided';
    case Refunded = 'refunded';
}
