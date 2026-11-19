<?php

namespace App\Domains\Catalog\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Searches for one normalised query on one day in this country (QC-043):
 * counts only — no user, visitor or IP is stored.
 */
#[Fillable(['day', 'query', 'hits', 'results_count'])]
class SearchQueryStat extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day' => 'immutable_date', 'hits' => 'integer', 'results_count' => 'integer'];
    }
}
