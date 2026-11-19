<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\ProductRequestStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to add a product to the country catalogue (FR-CAT-2), from a
 * store (`vendor`) or a marketer (`marketer`).
 *
 * @property ProductRequestStatus $status
 */
#[Fillable([
    'number', 'requester_type', 'vendor_id', 'marketer_profile_id', 'requested_by_user_id', 'title_en', 'title_ar', 'brand_name', 'brand_id',
    'category_id', 'gtin', 'model_number', 'description', 'reference_url', 'status', 'product_id', 'rejection_reason', 'reviewed_by', 'reviewed_at',
])]
class ProductRequest extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    public const REQUESTER_VENDOR = 'vendor';

    public const REQUESTER_MARKETER = 'marketer';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ProductRequestStatus::class, 'reviewed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
