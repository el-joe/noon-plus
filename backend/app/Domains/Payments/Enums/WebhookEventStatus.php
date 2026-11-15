<?php

namespace App\Domains\Payments\Enums;

/**
 * Gateway webhook event (lifecycle catalogue appendix A):
 * received → processed | ignored | failed. A second delivery of the same
 * provider event id never creates a row (unique key), it is a duplicate.
 */
enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
