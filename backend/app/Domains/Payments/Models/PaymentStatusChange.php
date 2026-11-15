<?php

namespace App\Domains\Payments\Models;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * History row of a payment status change; `source` says what reported it
 * (checkout, webhook, verify, expiry job, refund).
 */
#[Fillable(['payment_id', 'from_status', 'to_status', 'actor_type', 'actor_user_id', 'source', 'reason'])]
class PaymentStatusChange extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    protected $table = 'payment_status_history';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => PaymentStatus::class,
            'to_status' => PaymentStatus::class,
            'actor_type' => ActorType::class,
        ];
    }
}
