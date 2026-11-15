<?php

namespace App\Domains\Finance\Enums;

/**
 * Wallet buckets (ADR 4): one ledger account per owner × currency × bucket.
 * Customers: spendable (refunds, gift cards), promotional (expiring
 * credits), frozen (admin freeze). Vendors: pending (settled, COD not yet
 * remitted), spendable (eligible for the next statement), reserved (on a
 * payout statement being approved/processed). Marketers: pending,
 * spendable, reserved (withdrawal) and clawback (negative while a reversed,
 * already-paid commission is being recovered).
 */
enum WalletBucket: string
{
    case Pending = 'pending';
    case Spendable = 'spendable';
    case Reserved = 'reserved';
    case Frozen = 'frozen';
    case Promotional = 'promotional';
    case Clawback = 'clawback';
}
