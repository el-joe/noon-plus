<?php

namespace App\Domains\Listings\Models;

use App\Domains\Listings\Enums\ListingStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['listing_id', 'from_status', 'to_status', 'actor_user_id', 'reason'])]
class ListingStatusChange extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    protected $table = 'listing_status_history';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ListingStatus::class,
            'to_status' => ListingStatus::class,
        ];
    }
}
