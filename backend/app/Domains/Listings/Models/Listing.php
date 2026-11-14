<?php

namespace App\Domains\Listings\Models;

use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Listings\Support\ListingStateMachine;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A seller's offer on one product variant. Status changes only through
 * {@see ListingStateMachine}; stock only through
 * {@see InventoryLedger}.
 */
#[UseFactory(ListingFactory::class)]
#[Fillable(['vendor_id', 'product_variant_id', 'vendor_sku', 'price', 'sale_price', 'fulfilment', 'condition', 'handling_days'])]
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory, UsesCountryDatabase;

    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'draft'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'price' => 'decimal:4',
            'sale_price' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * @return HasMany<ListingStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ListingStatusChange::class);
    }

    /**
     * Price the customer pays: the sale price when set, otherwise the price.
     */
    public function effectivePrice(): string
    {
        return (string) ($this->sale_price ?? $this->price);
    }

    /**
     * Add `available_quantity` (sum of stock movements) to the query.
     *
     * @param  Builder<Listing>  $query
     */
    public function scopeWithAvailableQuantity(Builder $query): void
    {
        $query->withSum('movements as available_quantity', 'quantity');
    }
}
