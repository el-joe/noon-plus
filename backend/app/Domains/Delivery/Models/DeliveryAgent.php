<?php

namespace App\Domains\Delivery\Models;

use App\Domains\Delivery\Enums\AgentStatus;
use App\Models\User;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery agent's profile in one country (SRS §4.1.11, §4.6): the central
 * user, the carrier (null = Nawy fleet), vehicle, zones it serves (null =
 * every zone) and the last GPS ping.
 */
#[Fillable(['user_id', 'carrier_id', 'name', 'phone', 'vehicle_type', 'vehicle_plate', 'zone_ids', 'status', 'last_lat', 'last_lng', 'last_seen_at'])]
class DeliveryAgent extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => AgentStatus::class, 'zone_ids' => 'array', 'last_seen_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * @return HasMany<DeliveryAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class);
    }

    public function user(): User
    {
        return User::query()->findOrFail($this->user_id);
    }

    public function servesZone(?int $zoneId): bool
    {
        return $zoneId === null || empty($this->zone_ids) || in_array($zoneId, array_map('intval', $this->zone_ids), true);
    }
}
