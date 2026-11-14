<?php

namespace App\Domains\FlashSales\Models;

use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Support\FlashSaleSubmissionMachine;
use App\Domains\Listings\Models\Listing;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A seller's offer of one listing at a flash price, up to `quantity_cap`
 * units. Status changes only through {@see FlashSaleSubmissionMachine}.
 *
 * @property FlashSaleSubmissionStatus $status
 */
#[Fillable(['flash_sale_event_id', 'listing_id', 'vendor_id', 'original_price', 'flash_price', 'quantity_cap'])]
class FlashSaleSubmission extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'draft', 'sold_quantity' => 0];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FlashSaleSubmissionStatus::class,
            'original_price' => 'decimal:4',
            'flash_price' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<FlashSaleEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(FlashSaleEvent::class, 'flash_sale_event_id');
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return HasMany<FlashSaleSubmissionStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(FlashSaleSubmissionStatusChange::class)->orderBy('id');
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->quantity_cap - $this->sold_quantity);
    }
}
