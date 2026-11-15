<?php

namespace App\Domains\Documents\Models;

use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Enums\DocumentRequirement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A document requirement (central, settings catalogue `document_types`):
 * country × business type × category (null = every category) →
 * mandatory / optional / not applicable.
 *
 * @property DocumentOwner $business_type
 * @property DocumentRequirement $requirement
 */
#[Fillable(['country_id', 'code', 'name_en', 'name_ar', 'business_type', 'category_id', 'requirement', 'has_expiry', 'max_mb', 'is_active', 'sort_order'])]
class DocumentType extends Model
{
    use LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_type' => DocumentOwner::class,
            'requirement' => DocumentRequirement::class,
            'has_expiry' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->useLogName('documents');
    }
}
