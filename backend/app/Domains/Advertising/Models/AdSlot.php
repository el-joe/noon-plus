<?php

namespace App\Domains\Advertising\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A bookable ad placement (homepage / category) with price per day and the maximum concurrent bookings.
 * The travel placement (D14, placement `travel`) is sold as travel
 * promotions instead; its `max_concurrent` is how many promoted trips a
 * travel list shows.
 */
#[Fillable(['code', 'name', 'placement', 'category_id', 'dimensions', 'price_per_day', 'max_concurrent', 'is_active'])]
class AdSlot extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true, 'max_concurrent' => 1];

    /** D14: the travel promotions placement (not booked as a banner). */
    public const PLACEMENT_TRAVEL = 'travel';

    protected $table = 'ad_slots';

    /**
     * Slots vendors and marketers can book as banner ads (not the travel placement).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function bookable(Builder $query): void
    {
        $query->where('placement', '!=', self::PLACEMENT_TRAVEL);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_per_day' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
