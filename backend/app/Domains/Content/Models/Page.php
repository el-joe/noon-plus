<?php

namespace App\Domains\Content\Models;

use App\Domains\Content\Enums\PageStatus;
use App\Domains\Content\Enums\PageTemplate;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A page-builder page of one country and locale (SRS §15). Its blocks live
 * in append-only revisions: `current_revision_id` is the latest draft,
 * `published_revision_id` what the storefront serves.
 */
#[Fillable(['template', 'slug', 'locale', 'title', 'status', 'publish_at', 'published_at', 'current_revision_id', 'published_revision_id', 'seo_title', 'seo_description', 'updated_by'])]
class Page extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'draft'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => PageTemplate::class,
            'status' => PageStatus::class,
            'publish_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<PageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->orderBy('number');
    }

    /**
     * @return BelongsTo<PageRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(PageRevision::class, 'current_revision_id');
    }

    /**
     * @return BelongsTo<PageRevision, $this>
     */
    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(PageRevision::class, 'published_revision_id');
    }
}
