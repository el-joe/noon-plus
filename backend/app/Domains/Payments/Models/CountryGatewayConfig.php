<?php

namespace App\Domains\Payments\Models;

use App\Domains\Countries\Models\Country;
use App\Domains\Payments\Enums\GatewayMode;
use App\Domains\Payments\Enums\PaymentMethod;
use Database\Factories\CountryGatewayConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One payment gateway in one country (ADR 6, settings catalogue §5) —
 * central table. `credentials` are encrypted at rest, hidden from
 * serialisation and left out of the audit trail (only "credentials
 * changed" is logged, never the values). `fee_rule` is
 * `{percent, fixed, payer}`; the payer is the platform for now.
 *
 * @property PaymentMethod $gateway
 * @property GatewayMode $mode
 * @property array<string, string>|null $credentials
 * @property array<int, string> $currencies
 * @property array{percent?: string, fixed?: string, payer?: string}|null $fee_rule
 */
#[UseFactory(CountryGatewayConfigFactory::class)]
#[Fillable([
    'country_id', 'gateway', 'is_enabled', 'mode', 'credentials', 'currencies', 'min_amount', 'max_amount',
    'sort_order', 'is_recommended', 'fee_rule', 'updated_by',
])]
#[Hidden(['credentials'])]
class CountryGatewayConfig extends Model
{
    /** @use HasFactory<CountryGatewayConfigFactory> */
    use HasFactory, LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway' => PaymentMethod::class,
            'mode' => GatewayMode::class,
            'credentials' => 'encrypted:array',
            'currencies' => 'array',
            'fee_rule' => 'array',
            'is_enabled' => 'boolean',
            'is_recommended' => 'boolean',
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
     * Whether a credential field has a saved value (the value itself is never shown).
     */
    public function hasCredential(string $field): bool
    {
        return filled($this->credentials[$field] ?? null);
    }

    public function credential(string $field): ?string
    {
        $value = $this->credentials[$field] ?? null;

        return filled($value) ? (string) $value : null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payment_gateways')
            ->logOnly(['country_id', 'gateway', 'is_enabled', 'mode', 'currencies', 'min_amount', 'max_amount', 'sort_order', 'is_recommended', 'fee_rule'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
