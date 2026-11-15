<?php

namespace App\Domains\Catalog\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A size-guide template (apparel_alpha, abaya_height_length, shoes, custom) for a category, optionally owned by a seller.
 */
#[Fillable(['category_id', 'vendor_id', 'name', 'type', 'unit', 'columns', 'rows', 'notes', 'is_active'])]
class SizeGuideTemplate extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true, 'unit' => 'cm'];

    protected $table = 'size_guide_templates';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'rows' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
