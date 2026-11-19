<?php

namespace App\Domains\Vendors\Models;

use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Listings\Models\Listing;
use App\Shared\Database\UsesCountryDatabase;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A seller in one country (product vendor or classified business). The owner
 * is a central user id; the store lives in the country database.
 */
#[UseFactory(VendorFactory::class)]
#[Fillable(['owner_user_id', 'type', 'slug', 'store_name', 'contact_phone', 'logo_path', 'business_details', 'status', 'rating', 'rating_count', 'seller_agreement_accepted_at', 'payout_schedule', 'acquired_by_user_id', 'acquired_at'])]
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory, UsesCountryDatabase;

    public const STATUS_ACTIVE = 'active';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'rating_count' => 'integer',
            'seller_agreement_accepted_at' => 'immutable_datetime',
            'acquired_at' => 'immutable_datetime',
            'business_details' => 'array',
        ];
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * @return HasMany<VendorStrike, $this>
     */
    public function strikes(): HasMany
    {
        return $this->hasMany(VendorStrike::class);
    }

    /**
     * The current Seller Agreement is signed (contract engine, SRS §13.4);
     * countries without a published agreement keep the registration stamp.
     */
    public function hasAcceptedSellerAgreement(): bool
    {
        return app(ContractGates::class)->sellerAgreementAccepted($this);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * A travel agency (Travel Partner). Owner decision D11: travel pays no
     * commission and no marketer-campaign fees; paid advertising still applies.
     */
    public function isTravelAgency(): bool
    {
        return $this->type === 'travel';
    }
}
