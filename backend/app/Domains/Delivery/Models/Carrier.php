<?php

namespace App\Domains\Delivery\Models;

use App\Domains\Delivery\Enums\CarrierStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shipping company of one country (SRS §4.1.11 "Shipping Companies") or
 * Nawy's own fleet (`is_internal`). Its agents and supervisors are users
 * with `delivery_agent` / `carrier_supervisor` memberships, tenant = carrier.
 */
#[Fillable(['owner_user_id', 'code', 'name', 'licence_number', 'contact_name', 'contact_email', 'contact_phone', 'is_internal', 'status', 'details'])]
class Carrier extends Model
{
    use UsesCountryDatabase;

    public const TENANT = 'carrier';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => CarrierStatus::class, 'is_internal' => 'boolean', 'details' => 'array'];
    }

    /**
     * @return HasMany<DeliveryAgent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(DeliveryAgent::class);
    }

    /**
     * @return HasMany<CarrierClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(CarrierClaim::class);
    }
}
