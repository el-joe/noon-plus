<?php

namespace App\Domains\Payments\Enums;

/**
 * Gateway webhook event (lifecycle catalogue appendix A, QC-032):
 * received → verified → processed | duplicate | failed.
 *
 * - `received`: stored once per (gateway, provider event id) after the adapter checked the signature.
 * - `verified`: matched to one of our payments with an outcome to apply.
 * - `processed`: the outcome moved the payment.
 * - `duplicate`: the payment already had that outcome (the return page or an earlier event got there first);
 *   a redelivery of the same event id adds no row and only counts in `deliveries`.
 * - `failed`: applying the outcome threw; a redelivery processes it again.
 * - `ignored` (kept, outside the catalogue): signed but nothing to act on — an unknown payment or an event type with no outcome.
 */
enum WebhookEventStatus: string
{
    case Received = 'received';
    case Verified = 'verified';
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
