<?php

namespace App\Domains\Reviews\Models;

use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An answer to a product question by a seller of the product (`vendor`,
 * moderated) or by Nawy (`admin`, published at once).
 */
#[Fillable(['product_question_id', 'author_type', 'vendor_id', 'author_user_id', 'body', 'status', 'rejection_reason', 'moderated_at', 'moderated_by_user_id'])]
class ProductAnswer extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    public const AUTHOR_VENDOR = 'vendor';

    public const AUTHOR_ADMIN = 'admin';

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
     * @return BelongsTo<ProductQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ProductQuestion::class, 'product_question_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
