<?php

namespace App\Domains\Countries\Models;

use App\Shared\Http\FlushesPublicCache;
use App\Shared\Http\PublicCache;
use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A country store. Lives in the central database; each country also has its
 * own isolated operational database reachable through {@see connectionName()}.
 *
 * @property string $code
 * @property string $currency
 * @property string $timezone
 * @property array<int, string> $weekend
 * @property array<int, string> $locales
 */
#[UseFactory(CountryFactory::class)]
#[Fillable([
    'code', 'iso2', 'name_en', 'name_ar', 'currency', 'timezone', 'weekend',
    'tax_name', 'tax_rate', 'phone_code', 'locales', 'default_locale', 'is_active', 'sort_order',
])]
class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use FlushesPublicCache, HasFactory, LogsActivity;

    /**
     * Public API answers built from this model (forgotten on every change).
     *
     * @return array<int, string>
     */
    public function centralPublicCacheGroups(): array
    {
        return [PublicCache::COUNTRIES];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekend' => 'array',
            'locales' => 'array',
            'tax_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Public holidays (country-local dates).
     *
     * @return HasMany<CountryCalendar, $this>
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(CountryCalendar::class)->orderBy('starts_on');
    }

    /**
     * Name of this country's isolated database connection, e.g. "country_uae".
     */
    public function connectionName(): string
    {
        return config('nawy.country_connection_prefix').$this->code;
    }

    /**
     * Storefront market slug for a locale, e.g. "uae-en".
     */
    public function marketSlug(string $locale): string
    {
        return $this->code.'-'.$locale;
    }

    /**
     * Scope a query to active countries in display order.
     *
     * @param  Builder<Country>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Seed attributes for a country code from config/nawy.php.
     *
     * @return array<string, mixed>
     */
    public static function referenceAttributes(string $code): array
    {
        return ['code' => $code] + config("nawy.countries.{$code}");
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('countries')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
