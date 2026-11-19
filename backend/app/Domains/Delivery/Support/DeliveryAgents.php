<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Notifications\AgentCredentialsNotification;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Support\TeamInvitations;
use App\Models\User;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Delivery agents (SRS §4.1.11 "Delivery Agents", §4.6): created by an admin
 * (directly) or by a carrier supervisor for their company — then only
 * through an invitation the person accepts (QA-003, {@see TeamInvitations}) —
 * verified to active, the
 * agent toggles availability (active ⇄ unavailable), suspension blocks new
 * work. New accounts get their credentials by email (SRS §15).
 */
class DeliveryAgents
{
    public function __construct(private StateMachine $machine, private CountryContext $countryContext) {}

    /**
     * @param  array{email: string, name: string, phone?: string|null, carrier_id?: int|null, vehicle_type?: string|null, vehicle_plate?: string|null, zone_ids?: array<int, int>|null}  $data
     */
    public function create(array $data, ActorType $actorType, ?int $actorUserId, ?User $acceptedBy = null): DeliveryAgent
    {
        $country = $this->countryContext->current();
        $carrier = isset($data['carrier_id']) ? Carrier::query()->findOrFail($data['carrier_id']) : null;
        $password = null;
        $user = $acceptedBy ?? User::query()->where('email', Str::lower($data['email']))->first();

        if ($user === null) {
            $password = Str::password(14, symbols: false);
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);
        }

        if (DeliveryAgent::query()->where('user_id', $user->id)->exists()) {
            throw new TransitionRefused(__('This person is already a delivery agent in this country.'));
        }

        $agent = DB::connection((new DeliveryAgent)->getConnectionName())->transaction(function () use ($data, $carrier, $user, $actorType, $actorUserId): DeliveryAgent {
            $agent = DeliveryAgent::query()->create([
                'user_id' => $user->id,
                'carrier_id' => $carrier?->id,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'zone_ids' => empty($data['zone_ids']) ? null : array_values(array_map('intval', $data['zone_ids'])),
                'status' => AgentStatus::PendingDocuments,
            ]);
            $this->machine->created($agent, $actorType, $actorUserId);

            return $agent;
        });

        $user->memberships()->firstOrCreate([
            'role' => MembershipRole::DeliveryAgent,
            'country_id' => $country->id,
            'tenant_type' => $carrier === null ? null : Carrier::TENANT,
            'tenant_id' => $carrier?->id,
        ], ['status' => 'active']);

        if ($password !== null) {
            $user->notify(new AgentCredentialsNotification($country->name_en, $password, $carrier?->name));
        }

        return $agent;
    }

    public function move(DeliveryAgent $agent, AgentStatus $to, ActorType $actorType, ?int $actorUserId, ?string $reason = null): DeliveryAgent
    {
        if ($to === AgentStatus::Unavailable && $agent->assignments()->whereIn('status', [AssignmentStatus::PickedUp])->exists()) {
            throw new TransitionRefused(__('Finish the deliveries on the way before going unavailable.'));
        }

        if ($to === AgentStatus::Suspended && $agent->assignments()->whereIn('status', AssignmentStatus::open())->exists()) {
            throw new TransitionRefused(__('Reassign this driver\'s open deliveries (or let them finish the ones on the way) before suspending them.'));
        }

        return $this->machine->move($agent, $to, $actorType, $actorUserId, $reason);
    }

    /**
     * The agent's own availability toggle (My day).
     */
    public function setAvailable(DeliveryAgent $agent, bool $available, int $agentUserId): DeliveryAgent
    {
        $to = $available ? AgentStatus::Active : AgentStatus::Unavailable;

        if ($agent->status === $to) {
            return $agent;
        }

        if (! in_array($agent->status, [AgentStatus::Active, AgentStatus::Unavailable], true)) {
            throw new TransitionRefused(__('Your account is not active yet.'));
        }

        return $this->move($agent, $to, ActorType::Agent, $agentUserId);
    }

    public function ping(DeliveryAgent $agent, float $lat, float $lng): DeliveryAgent
    {
        $agent->forceFill(['last_lat' => $lat, 'last_lng' => $lng, 'last_seen_at' => now()])->save();

        return $agent;
    }
}
