<?php

namespace App\Domains\Reviews\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A verified-buyer review of one delivered order item (G-W3, SRS §7.8):
 * 1–5 stars, optional title, text and photos; public once published (QC-012 states).
 */
#[Fillable([
    'product_id', 'vendor_id', 'order_item_id', 'customer_user_id', 'customer_name', 'rating', 'title', 'body', 'locale',
    'status', 'rejection_reason', 'flag_reason', 'helpful_count', 'report_count', 'moderated_at', 'moderated_by_user_id',
])]
class ProductReview extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'rating' => 'integer',
            'helpful_count' => 'integer',
            'report_count' => 'integer',
            'moderated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return HasMany<ProductReviewPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ProductReviewPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<ProductReviewVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(ProductReviewVote::class);
    }

    /**
     * @return HasOne<ProductReviewReply, $this>
     */
    public function reply(): HasOne
    {
        return $this->hasOne(ProductReviewReply::class);
    }

    /**
     * @return HasMany<ProductReviewReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(ProductReviewReport::class);
    }
}
