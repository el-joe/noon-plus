<?php

namespace App\Domains\Countries\Models;

use Database\Factories\CountryCalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A public holiday (one or more consecutive days, country-local dates) that
 * delivery ETAs, SLA clocks and payout dates skip (country-reference §6).
 * Central table: reference data next to `countries.weekend`.
 *
 * @property \Carbon\CarbonImmutable $starts_on
 * @property \Carbon\CarbonImmutable $ends_on
 */
#[UseFactory(CountryCalendarFactory::class)]
#[Fillable(['country_id', 'starts_on', 'ends_on', 'name_en', 'name_ar', 'is_confirmed', 'updated_by'])]
class CountryCalendar extends Model
{
    /** @use HasFactory<CountryCalendarFactory> */
    use HasFactory, LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_confirmed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('country_calendars')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
