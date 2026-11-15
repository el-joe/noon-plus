<?php

namespace App\Domains\Contracts\Models;

use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\ContractVersionStatus;
use App\Domains\Countries\Models\Country;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A contract template (central, ADR 8): kind and country are fixed at
 * creation; `country_id` null = legacy/global, which serves only countries
 * without their own template. Editor or uploaded-file mode; consent by
 * click-wrap or (Seller Agreement only) notice.
 *
 * @property ContractKind $kind
 */
#[Fillable(['country_id', 'kind', 'name', 'mode', 'consent_mode', 'is_default', 'required_for_customers', 'open_call', 'destinations', 'created_by'])]
class ContractTemplate extends Model
{
    use LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ContractKind::class,
            'is_default' => 'boolean',
            'required_for_customers' => 'boolean',
            'open_call' => 'boolean',
            'destinations' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return HasMany<ContractVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class)->orderByDesc('version');
    }

    /**
     * The version in force (newest published).
     *
     * @return HasOne<ContractVersion, $this>
     */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(ContractVersion::class)->ofMany(['version' => 'max'], fn ($query) => $query->where('status', ContractVersionStatus::Published));
    }

    /**
     * @return HasMany<ContractAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ContractAssignment::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->useLogName('contracts');
    }
}
