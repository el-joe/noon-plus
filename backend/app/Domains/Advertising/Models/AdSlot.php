<?php

namespace App\Domains\Advertising\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A bookable ad placement (homepage / category) with price per day and the maximum concurrent bookings.
 */
#[Fillable(['code', 'name', 'placement', 'category_id', 'dimensions', 'price_per_day', 'max_concurrent', 'is_active'])]
class AdSlot extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true, 'max_concurrent' => 1];

    protected $table = 'ad_slots';

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
