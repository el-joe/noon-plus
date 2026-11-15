<?php

namespace App\Domains\Catalog\Models;

use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Database\UsesCountryDatabase;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(BrandFactory::class)]
#[Fillable(['slug', 'name', 'name_ar', 'logo_path', 'is_active'])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, LogsCountryActivity, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
