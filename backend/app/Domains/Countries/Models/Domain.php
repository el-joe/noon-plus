<?php

namespace App\Domains\Countries\Models;

use App\Domains\Countries\Enums\DomainKind;
use App\Domains\Countries\Support\DomainMap;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A hostname mapping (central, QC-044). Saving or deleting one forgets
 * the cached map ({@see DomainMap}).
 */
#[Fillable(['hostname', 'kind', 'country_id', 'locale', 'panel', 'is_primary', 'is_active', 'redirect_to', 'tls_status', 'dns_verified_at', 'note', 'updated_by'])]
class Domain extends Model
{
    use LogsActivity;

    public const TLS_STATUSES = ['pending', 'issued', 'failed'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DomainKind::class,
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'dns_verified_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => DomainMap::forget());
        static::deleted(fn () => DomainMap::forget());
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
            ->useLogName('domains')
            ->logOnly(['hostname', 'kind', 'country_id', 'locale', 'panel', 'is_primary', 'is_active', 'redirect_to', 'tls_status', 'dns_verified_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
