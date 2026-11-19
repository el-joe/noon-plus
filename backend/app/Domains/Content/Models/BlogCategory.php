<?php

namespace App\Domains\Content\Models;

use App\Shared\Database\UsesCountryDatabase;
use App\Shared\Http\FlushesPublicCache;
use App\Shared\Http\PublicCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nested blog category (SRS §15).
 */
#[Fillable(['parent_id', 'slug', 'name_en', 'name_ar', 'sort_order'])]
class BlogCategory extends Model
{
    use FlushesPublicCache, UsesCountryDatabase;

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
     * @return BelongsTo<BlogCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
