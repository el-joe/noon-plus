<?php

namespace App\Domains\Advertising\Models;

use App\Domains\Advertising\Enums\SubscriptionStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subscription to an ad package, paid by wallet (active at once) or bank transfer (pending until approved).
 */
#[Fillable(['ad_package_id', 'subscriber_type', 'subscriber_id', 'payment_method', 'proof_path', 'currency', 'amount', 'vat_amount', 'starts_at', 'expires_at', 'rejection_reason'])]
class AdPackageSubscription extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'ad_package_subscriptions';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AdPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(AdPackage::class, 'ad_package_id');
    }
}
