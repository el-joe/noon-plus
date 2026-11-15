<?php

namespace App\Domains\Content\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bilingual help-centre or knowledge-hub article (pinned articles first).
 */
#[Fillable(['collection_id', 'hub', 'slug', 'title_en', 'title_ar', 'body_en', 'body_ar', 'is_pinned', 'is_published', 'sort_order', 'views', 'helpful_count', 'not_helpful_count'])]
class HelpArticle extends Model
{
    use UsesCountryDatabase;

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
