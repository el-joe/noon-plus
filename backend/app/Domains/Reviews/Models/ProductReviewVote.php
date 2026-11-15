<?php

namespace App\Domains\Reviews\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One customer's "helpful" vote on a review (one per customer and review).
 */
#[Fillable(['product_review_id', 'user_id'])]
class ProductReviewVote extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;
}
