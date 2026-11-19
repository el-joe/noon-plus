<?php

namespace App\Domains\Content\Models;

use App\Domains\Content\Enums\PageStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\Http\FlushesPublicCache;
use App\Shared\Http\PublicCache;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bilingual blog post (SRS §15, PORTAL_PAGES blog): tags, featured flag,
 * view counter and scheduled publishing.
 */
#[Fillable([
    'category_id', 'slug', 'title_en', 'title_ar', 'excerpt_en', 'excerpt_ar', 'body_en', 'body_ar', 'cover_path', 'tags',
    'is_featured', 'status', 'publish_at', 'published_at', 'views', 'author_user_id',
])]
class BlogPost extends Model
{
    use FlushesPublicCache, HasStateTransitions, UsesCountryDatabase;

    /**
     * Public API answers built from this model (forgotten on every change).
     *
     * @return array<int, string>
     */
    public function publicCacheGroups(): array
    {
        return [PublicCache::BLOG_CATEGORIES];
    }

    /**
     * Counters that change on reads; they alone keep the cached answers.
     *
     * @return array<int, string>
     */
    public function publicCacheIgnoredColumns(): array
    {
        return ['views'];
    }

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
            'status' => PageStatus::class,
            'tags' => 'array',
            'is_featured' => 'boolean',
            'publish_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BlogCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class);
    }
}
