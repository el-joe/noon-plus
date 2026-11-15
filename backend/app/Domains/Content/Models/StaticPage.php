<?php

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A static page (SRS §15, PORTAL_PAGES §12): About, Terms, Privacy, Return,
 * Shipping, Refund, Contact… Country row overrides the global one
 * (country_id null). Audience storefront | portal | both.
 */
#[Fillable(['country_id', 'slug', 'audience', 'title_en', 'title_ar', 'body_en', 'body_ar', 'seo_title_en', 'seo_title_ar', 'seo_description_en', 'seo_description_ar', 'is_published', 'updated_by'])]
class StaticPage extends Model
{
    use LogsActivity;

    public const AUDIENCES = ['storefront', 'portal', 'both'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->useLogName('content');
    }
}
