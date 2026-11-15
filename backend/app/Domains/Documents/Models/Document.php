<?php

namespace App\Domains\Documents\Models;

use App\Domains\Catalog\Models\Category;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded document or product certification (country database). The
 * file is on the private disk and only served through authorised routes.
 *
 * @property DocumentStatus $status
 */
#[Fillable(['document_type_id', 'owner_type', 'owner_id', 'category_id', 'file_path', 'original_name', 'mime', 'size', 'status', 'rejection_reason', 'valid_until', 'uploaded_by_user_id', 'reviewed_by_user_id', 'reviewed_at'])]
class Document extends Model
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
            'status' => DocumentStatus::class,
            'valid_until' => 'immutable_date',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function type(): ?DocumentType
    {
        return DocumentType::query()->find($this->document_type_id);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
