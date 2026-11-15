<?php

namespace App\Domains\Content\Models;

use App\Domains\Content\Enums\PageTemplate;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved block layout for a page template, to start new pages from.
 */
#[Fillable(['name', 'template', 'zones', 'created_by'])]
class PageLayoutTemplate extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['template' => PageTemplate::class, 'zones' => 'array'];
    }
}
