<?php

namespace App\Domains\Classifieds\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A purchase of a package for an ad, paid through the payments domain (payable).
 */
#[Fillable(['number', 'classified_ad_id', 'classified_package_id', 'customer_user_id', 'currency', 'amount', 'status', 'paid_at', 'applied_until'])]
class ClassifiedPackagePurchase extends Model
{
    use UsesCountryDatabase;

    /** `payments.payable_type` of a package purchase. */
    public const PAYABLE = 'classified_package';

    protected $table = 'classified_package_purchases';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'paid_at' => 'immutable_datetime',
            'applied_until' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ClassifiedAd, $this>
     */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(ClassifiedAd::class, 'classified_ad_id');
    }

    /**
     * @return BelongsTo<ClassifiedPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ClassifiedPackage::class, 'classified_package_id');
    }
}
