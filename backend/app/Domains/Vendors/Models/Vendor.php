<?php

namespace App\Domains\Vendors\Models;

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
#[Fillable(['owner_user_id', 'type', 'slug', 'store_name', 'status', 'rating'])]
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
        return ['rating' => 'decimal:2'];
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
