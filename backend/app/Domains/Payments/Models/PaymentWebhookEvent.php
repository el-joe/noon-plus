<?php

namespace App\Domains\Payments\Models;

use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\WebhookEventStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A verified gateway notification, unique per (gateway, event id): the
 * provider's redeliveries are recognised and ignored (risk T9).
 */
#[Fillable(['gateway', 'event_id', 'type', 'payment_id', 'status', 'payload', 'error', 'processed_at'])]
class PaymentWebhookEvent extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway' => PaymentMethod::class,
            'status' => WebhookEventStatus::class,
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
