<?php

namespace App\Domains\Reviews\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's or the reviewed store's report of a published review
 * (QC-012); the first one flags the review for moderation.
 */
#[Fillable(['product_review_id', 'reporter_user_id', 'reporter_type', 'vendor_id', 'reason', 'note'])]
class ProductReviewReport extends Model
{
    use UsesCountryDatabase;

    public const REASONS = ['spam', 'offensive', 'fake', 'irrelevant', 'personal_data', 'other'];

    /**
     * @return BelongsTo<ProductReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'product_review_id');
    }
}
