<?php

namespace App\Domains\Content\Models;

use App\Shared\Database\UsesCountryDatabase;
use App\Shared\Http\FlushesPublicCache;
use App\Shared\Http\PublicCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bilingual help-centre or knowledge-hub article (pinned articles first).
 */
#[Fillable(['collection_id', 'hub', 'slug', 'title_en', 'title_ar', 'body_en', 'body_ar', 'is_pinned', 'is_published', 'sort_order', 'views', 'helpful_count', 'not_helpful_count'])]
class HelpArticle extends Model
{
    use FlushesPublicCache, UsesCountryDatabase;

    /**
     * Public API answers built from this model (forgotten on every change).
     *
     * @return array<int, string>
     */
    public function publicCacheGroups(): array
    {
        return [PublicCache::HELP];
    }

    /**
     * Counters that change on reads; they alone keep the cached answers.
     *
     * @return array<int, string>
     */
    public function publicCacheIgnoredColumns(): array
    {
        return ['views', 'helpful_count', 'not_helpful_count'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_pinned' => 'boolean', 'is_published' => 'boolean'];
    }

    /**
     * @return BelongsTo<HelpCollection, $this>
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(HelpCollection::class, 'collection_id');
    }
}
