<?php

namespace App\Domains\Content\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category of the help centre (`hub` help_center, audience customer or
 * seller) or a collection of the Ads Knowledge Hub (`hub` knowledge_hub).
 */
#[Fillable(['hub', 'audience', 'slug', 'title_en', 'title_ar', 'description_en', 'description_ar', 'sort_order'])]
class HelpCollection extends Model
{
    use UsesCountryDatabase;

    public const HUBS = ['help_center', 'knowledge_hub'];

    public const AUDIENCES = ['customer', 'seller'];

    /**
     * @return HasMany<HelpArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(HelpArticle::class, 'collection_id')->orderByDesc('is_pinned')->orderBy('sort_order')->orderBy('id');
    }
}
