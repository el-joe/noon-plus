<?php

namespace App\Domains\Marketers\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A marketing agency that manages several marketers (MP §2, FR-MKT-17).
 * Its users hold a `marketer:agency` membership with tenant = this agency.
 */
#[Fillable(['owner_user_id', 'name', 'slug', 'status'])]
class MarketingAgency extends Model
{
    use UsesCountryDatabase;

    /**
     * @return HasMany<MarketerProfile, $this>
     */
    public function marketers(): HasMany
    {
        return $this->hasMany(MarketerProfile::class, 'agency_id');
    }
}
