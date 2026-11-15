<?php

namespace App\Domains\Content\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer-app super-app context (ADR 16, SRS §15 "App contexts"): its
 * header theme, home layout (an `app_context` page) and bottom tab bar, per
 * country.
 */
#[Fillable(['key', 'name_en', 'name_ar', 'icon', 'sort_order', 'is_active', 'theme', 'tabs', 'home_page_id'])]
class AppContext extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'theme' => 'array', 'tabs' => 'array'];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function homePage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'home_page_id');
    }
}
