<?php

namespace App\Domains\Countries\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Operational audit trail stored in each country's own database.
 */
#[Fillable(['actor_user_id', 'action', 'subject_type', 'subject_id', 'properties', 'occurred_at'])]
class CountryAuditLog extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
