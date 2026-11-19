<?php

namespace App\Domains\Finance\Enums;

/**
 * How a vendor pays its subscription invoices: deducted from the next payout
 * statement (default) or by card through the payment gateway.
 */
enum SubscriptionPaymentPreference: string
{
    case Payout = 'payout';
    case Card = 'card';
}
