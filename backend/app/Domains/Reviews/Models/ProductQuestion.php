<?php

namespace App\Domains\Reviews\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\Reviews\Enums\ModerationStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's question on a product page (G-W3 Q&A); public once approved.
 */
#[Fillable(['product_id', 'customer_user_id', 'customer_name', 'body', 'locale', 'status', 'rejection_reason', 'moderated_at', 'moderated_by_user_id'])]
class ProductQuestion extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ModerationStatus::class, 'moderated_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<ProductAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ProductAnswer::class)->orderBy('id');
    }
}
