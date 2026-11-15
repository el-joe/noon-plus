<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Identity\Enums\MembershipRole;
use App\Models\User;

/**
 * The signed-in delivery agent of the current country (role-permission
 * matrix §9, T8): an active `delivery_agent` membership in the country and
 * a profile that is not suspended. Agents only ever reach their own
 * assignments; any other number is a 404.
 */
class AgentWorkspace
{
    public function __construct(private CountryContext $countryContext) {}

    public function agentFor(User $user): ?DeliveryAgent
    {
        if (! $this->countryContext->has() || ! $user->memberships()
            ->where('role', MembershipRole::DeliveryAgent)
            ->where('status', 'active')
            ->where('country_id', $this->countryContext->current()->id)
            ->exists()) {
            return null;
        }

        $agent = DeliveryAgent::query()->where('user_id', $user->id)->first();

        return $agent === null || $agent->status === AgentStatus::Suspended ? null : $agent;
    }

    public function assignment(DeliveryAgent $agent, string $number): DeliveryAssignment
    {
        return DeliveryAssignment::query()->where('delivery_agent_id', $agent->id)->where('number', $number)->firstOrFail();
    }
}
