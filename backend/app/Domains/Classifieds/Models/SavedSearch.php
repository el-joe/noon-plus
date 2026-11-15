<?php

namespace App\Domains\Classifieds\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer saved search with its filters and alert frequency (instant | daily).
 */
#[Fillable(['customer_user_id', 'name', 'filters', 'alert_frequency', 'status', 'last_alerted_at', 'checked_until'])]
class SavedSearch extends Model
{
    use UsesCountryDatabase;

    protected $table = 'saved_searches';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'last_alerted_at' => 'immutable_datetime',
            'checked_until' => 'immutable_datetime',
        ];
    }
}
