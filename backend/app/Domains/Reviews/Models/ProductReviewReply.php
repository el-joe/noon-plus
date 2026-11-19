<?php

namespace App\Domains\Reviews\Models;

use App\Domains\Reviews\Enums\ReplyStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The store's public reply to a review (QC-012), shown once an admin
 * publishes it.
 */
#[Fillable(['product_review_id', 'vendor_id', 'author_user_id', 'body', 'status', 'rejection_reason', 'moderated_at', 'moderated_by_user_id'])]
class ProductReviewReply extends Model
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
            'status' => ReplyStatus::class,
            'moderated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'product_review_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
