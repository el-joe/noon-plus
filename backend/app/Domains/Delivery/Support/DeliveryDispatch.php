<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Countries\Support\BusinessCalendar;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\CarrierStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Events\DeliveryDispatchUpdated;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Finance\Support\CodSettlements;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Shared\Money\MoneyFactory;
use App\Shared\Notifications\Email\Listeners\CommerceLifecycleEmails;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dispatch side of delivery (SRS §4.1.11, §4.7, §8; lifecycle catalogue
 * §2.3–2.4): assign a sub-order to an agent (manual or automatic), reassign
 * with a new code, reschedule a failed shipment, return it to origin and
 * confirm it back — which cancels the sub-order through the
 * {@see SubOrderStateMachine} (stock back, refund). Every move writes a
 * history row with the actor and is broadcast on `country.{code}.ops`.
 */
class DeliveryDispatch
{
    public function __construct(
        private StateMachine $machine,
        private SubOrderStateMachine $subOrders,
        private DeliverySettings $settings,
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private BusinessCalendar $calendar,
        private DeliveryNotices $notices,
    ) {}

    /**
     * Assign the sub-order's next delivery attempt to an agent. Guards: the
     * sub-order is packed / handed over / out for delivery, the shipment has
     * no open attempt and may be assigned, the agent is active, serves the
     * delivery zone and (for a carrier supervisor) works for that carrier.
     */
    public function assign(SubOrder $subOrder, DeliveryAgent $agent, ActorType $actorType, ?int $actorUserId = null, ?Carrier $actingCarrier = null, string $via = 'manual'): DeliveryAssignment
    {
        if (! in_array($subOrder->status, [SubOrderStatus::Packed, SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery], true)) {
            throw new TransitionRefused(__('Only packed or handed-over orders can be assigned for delivery.'));
        }

        if ($agent->status !== AgentStatus::Active) {
            throw new TransitionRefused(__('The agent is not active and available.'));
        }

        if ($actingCarrier !== null && $agent->carrier_id !== $actingCarrier->id) {
            throw new TransitionRefused(__('The agent does not work for your company.'));
        }

        if (! $agent->servesZone($subOrder->shipping_zone_id)) {
            throw new TransitionRefused(__('The agent does not serve this delivery zone.'));
        }

        $assignment = DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $agent, $actorType, $actorUserId, $via, $actingCarrier): DeliveryAssignment {
            $shipment = $this->shipmentFor($subOrder, $actorType, $actorUserId);

            if ($actingCarrier !== null && $shipment->carrier_id !== $actingCarrier->id) {
                throw new TransitionRefused(__('This shipment was not handed to your company.'));
            }

            if (! $shipment->status->isAssignable()) {
                throw new TransitionRefused(__('This shipment cannot be assigned while it is :status.', ['status' => str_replace('_', ' ', $shipment->status->value)]));
            }

            if ($shipment->assignments()->whereIn('status', AssignmentStatus::open())->exists()) {
                throw new TransitionRefused(__('This shipment already has an agent. Reassign it instead.'));
            }

            $assignment = DeliveryAssignment::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'shipment_id' => $shipment->id,
                'sub_order_id' => $subOrder->id,
                'delivery_agent_id' => $agent->id,
                'carrier_id' => $agent->carrier_id,
                'attempt_no' => $shipment->attempts + 1,
                'status' => AssignmentStatus::Assigned,
                'delivery_code' => $this->newCode(),
                'assigned_via' => $via,
                'assigned_by_user_id' => $actorUserId,
                'currency' => $this->countryContext->current()->currency,
                'cod_amount' => $this->expectedCod($subOrder),
                'sla_due_at' => now()->addMinutes($this->settings->slaMinutes()),
            ]);
            $assignment->forceFill(['number' => 'DA-'.$subOrder->number.'-'.$assignment->id])->save();
            $this->machine->created($assignment, $actorType, $actorUserId, $via === 'auto' ? 'auto-assigned' : null);
            $shipment->forceFill(['carrier_id' => $agent->carrier_id])->save();

            return $assignment;
        });

        $this->broadcastAssignment('assigned', $assignment);
        app(CommerceLifecycleEmails::class)->deliveryAssigned($assignment);

        return $assignment;
    }

    /**
     * Hand a ready sub-order to a shipping company (WP8 carrier panel
     * "Pickup requests"): its shipment is created or re-pointed to the
     * carrier while no agent holds it; the company's supervisors then
     * assign one of their agents.
     */
    public function allocate(SubOrder $subOrder, Carrier $carrier, ActorType $actorType, ?int $actorUserId = null): Shipment
    {
        if (! in_array($subOrder->status, [SubOrderStatus::Packed, SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery], true)) {
            throw new TransitionRefused(__('Only packed or handed-over orders can be handed to a shipping company.'));
        }

        if ($carrier->status !== CarrierStatus::Active) {
            throw new TransitionRefused(__('This shipping company is suspended.'));
        }

        $shipment = DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $carrier, $actorType, $actorUserId): Shipment {
            $shipment = $this->shipmentFor($subOrder, $actorType, $actorUserId);

            if (! $shipment->status->isAssignable() || $shipment->assignments()->whereIn('status', AssignmentStatus::open())->exists()) {
                throw new TransitionRefused(__('This shipment is already with an agent.'));
            }

            $shipment->forceFill(['carrier_id' => $carrier->id])->save();

            return $shipment;
        });

        $this->broadcastShipment('allocated', $shipment);

        return $shipment;
    }

    /**
     * Automatic assignment (`delivery.auto_assign`): the active agent serving
     * the zone with the fewest open assignments; round robin breaks ties by
     * the agent assigned longest ago, "nearest" by the freshest GPS ping
     * (addresses carry no coordinates yet).
     */
    public function autoAssign(SubOrder $subOrder, ActorType $actorType = ActorType::System, ?int $actorUserId = null, ?Carrier $actingCarrier = null): DeliveryAssignment
    {
        $mode = $this->settings->autoAssign();

        if ($mode === 'off') {
            throw new TransitionRefused(__('Automatic assignment is switched off in this country.'));
        }

        $candidates = DeliveryAgent::query()
            ->where('status', AgentStatus::Active)
            ->when($actingCarrier !== null, fn ($query) => $query->where('carrier_id', $actingCarrier->id))
            ->withCount(['assignments as open_count' => fn ($query) => $query->whereIn('status', AssignmentStatus::open())])
            ->withMax('assignments as last_assignment_id', 'id')
            ->get()
            ->filter(fn (DeliveryAgent $agent): bool => $agent->servesZone($subOrder->shipping_zone_id))
            ->sortBy(fn (DeliveryAgent $agent): array => [
                $agent->open_count,
                $mode === 'round_robin' ? (int) $agent->last_assignment_id : -($agent->last_seen_at?->getTimestamp() ?? 0),
                $agent->id,
            ]);

        $agent = $candidates->first();

        if ($agent === null) {
            throw new TransitionRefused(__('No available agent serves this delivery zone.'));
        }

        return $this->assign($subOrder, $agent, $actorType, $actorUserId, $actingCarrier, 'auto');
    }

    /**
     * Move an open (not yet picked up) assignment to another agent: the old
     * one ends `reassigned`, the new one gets a new code.
     */
    public function reassign(DeliveryAssignment $assignment, DeliveryAgent $agent, ActorType $actorType, ?int $actorUserId = null, ?Carrier $actingCarrier = null, ?string $reason = null): DeliveryAssignment
    {
        if ($actingCarrier !== null && $assignment->carrier_id !== $actingCarrier->id) {
            throw new TransitionRefused(__('This assignment belongs to another company.'));
        }

        if ($agent->id === $assignment->delivery_agent_id) {
            throw new TransitionRefused(__('Pick a different agent.'));
        }

        return DB::connection($assignment->getConnectionName())->transaction(function () use ($assignment, $agent, $actorType, $actorUserId, $actingCarrier, $reason): DeliveryAssignment {
            $this->machine->move($assignment, AssignmentStatus::Reassigned, $actorType, $actorUserId, $reason);
            $this->broadcastAssignment('reassigned', $assignment);

            return $this->assign($assignment->subOrder()->firstOrFail(), $agent, $actorType, $actorUserId, $actingCarrier);
        });
    }

    /**
     * A failed shipment gets a new delivery day (country business day,
     * today up to `delivery.reschedule_max_days` business days ahead) while
     * attempts are left; the "Get it by" date follows.
     */
    public function reschedule(Shipment $shipment, string $localDate, ActorType $actorType, ?int $actorUserId = null, ?Carrier $actingCarrier = null): Shipment
    {
        $this->guardCarrier($shipment, $actingCarrier);
        $country = $this->countryContext->current();

        if ($shipment->attempts >= $this->settings->maxAttempts()) {
            throw new TransitionRefused(__('No attempts are left; return the shipment to origin.'));
        }

        $today = CarbonImmutable::now($country->timezone)->startOfDay();
        $date = CarbonImmutable::createFromFormat('Y-m-d', $localDate, $country->timezone)->startOfDay();
        $latest = $this->calendar->addBusinessDays($country, $today, $this->settings->rescheduleMaxDays());

        if ($date->lessThan($today) || $date->greaterThan($latest)) {
            throw new TransitionRefused(__('Pick a day between today and :date.', ['date' => $latest->toDateString()]));
        }

        if (! $this->calendar->isBusinessDay($country, $date)) {
            throw new TransitionRefused(__('That day is a weekend or a public holiday.'));
        }

        $shipment = DB::connection($shipment->getConnectionName())->transaction(function () use ($shipment, $date, $actorType, $actorUserId): Shipment {
            $this->machine->move($shipment, ShipmentStatus::Rescheduled, $actorType, $actorUserId, null, ['rescheduled_for' => $date->toDateString()]);
            $shipment->subOrder()->update(['estimated_delivery_by' => $date->toDateString()]);

            return $shipment;
        });

        $this->broadcastShipment('rescheduled', $shipment);
        $this->notices->shipment('delivery.rescheduled', $shipment->refresh());

        return $shipment;
    }

    /**
     * Send a failed or rescheduled shipment back to the seller / warehouse
     * (max attempts reached, refused, or a supervisor's call).
     */
    public function returnToOrigin(Shipment $shipment, ActorType $actorType, ?int $actorUserId = null, ?string $reason = null, ?Carrier $actingCarrier = null): Shipment
    {
        $this->guardCarrier($shipment, $actingCarrier);
        $this->machine->move($shipment, ShipmentStatus::ReturningToOrigin, $actorType, $actorUserId, $reason);
        $this->broadcastShipment('returning_to_origin', $shipment);

        return $shipment;
    }

    /**
     * The shipment is back at the origin: it ends `returned` and the
     * sub-order is cancelled through its state machine (stock back, flash
     * units back, warranty cancelled, refund of what was paid).
     */
    public function confirmReturned(Shipment $shipment, ActorType $actorType, ?int $actorUserId = null, ?Carrier $actingCarrier = null): Shipment
    {
        $this->guardCarrier($shipment, $actingCarrier);

        DB::connection($shipment->getConnectionName())->transaction(function () use ($shipment, $actorType, $actorUserId): void {
            $this->machine->move($shipment, ShipmentStatus::Returned, $actorType, $actorUserId, null, ['returned_at' => now()]);
            $subOrder = $shipment->subOrder()->firstOrFail();

            if (in_array(SubOrderStatus::Cancelled, $subOrder->status->allowedNext(), true)) {
                $this->subOrders->transition($subOrder, SubOrderStatus::Cancelled, $actorType, $actorUserId, __('Returned to origin after failed delivery'));
            }
        });

        $this->broadcastShipment('returned', $shipment);

        return $shipment;
    }

    /**
     * A sub-order was cancelled (QA-005): its open assignments end
     * `cancelled` (the code stops showing) and its shipment is cancelled
     * before pickup, or sent back to origin when the parcel already left.
     */
    public function closeForCancelledSubOrder(SubOrder $subOrder, ActorType $actorType, ?int $actorUserId = null): void
    {
        $reason = __('Order cancelled');

        DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $actorType, $actorUserId, $reason): void {
            foreach (DeliveryAssignment::query()->where('sub_order_id', $subOrder->id)->whereIn('status', AssignmentStatus::open())->lockForUpdate()->get() as $assignment) {
                $this->machine->move($assignment, AssignmentStatus::Cancelled, $actorType, $actorUserId, $reason);
                $this->broadcastAssignment('cancelled', $assignment);
            }

            $shipment = Shipment::query()->where('sub_order_id', $subOrder->id)->lockForUpdate()->first();

            if ($shipment === null) {
                return;
            }

            $to = match (true) {
                in_array(ShipmentStatus::Cancelled, $shipment->status->allowedNext(), true) => ShipmentStatus::Cancelled,
                in_array(ShipmentStatus::ReturningToOrigin, $shipment->status->allowedNext(), true) => ShipmentStatus::ReturningToOrigin,
                default => null,
            };

            if ($to !== null) {
                $this->machine->move($shipment, $to, $actorType, $actorUserId, $reason);
                $this->broadcastShipment($to->value, $shipment);
            }
        });
    }

    /**
     * The shipment of a sub-order (created with the first assignment).
     */
    public function shipmentFor(SubOrder $subOrder, ActorType $actorType, ?int $actorUserId = null): Shipment
    {
        $shipment = Shipment::query()->where('sub_order_id', $subOrder->id)->lockForUpdate()->first();

        if ($shipment !== null) {
            return $shipment;
        }

        $shipment = Shipment::query()->create([
            'number' => 'SH-'.$subOrder->number,
            'sub_order_id' => $subOrder->id,
            'status' => ShipmentStatus::LabelCreated,
        ]);
        $this->machine->created($shipment, $actorType, $actorUserId);

        return $shipment;
    }

    /**
     * Cash the agent should collect for a COD sub-order (items + warranty +
     * the order's COD fee on the one parcel that carries it, QA-006),
     * as stored; null when prepaid.
     */
    public function expectedCod(SubOrder $subOrder): ?string
    {
        $order = $subOrder->order()->firstOrFail();

        if ($order->payment_method !== PaymentMethod::Cod->value) {
            return null;
        }

        $amount = $this->money->of((string) $subOrder->total, $order->currency)->plus($this->money->of((string) $subOrder->warranty_total, $order->currency));

        if (app(CodSettlements::class)->carriesCodFee($subOrder)) {
            $amount = $amount->plus($this->money->of((string) $order->cod_fee, $order->currency));
        }

        return $this->money->toStorage($amount);
    }

    public function broadcastAssignment(string $event, DeliveryAssignment $assignment): void
    {
        event(DeliveryDispatchUpdated::forAssignment($this->countryContext->current()->code, $event, $assignment));
    }

    public function broadcastShipment(string $event, Shipment $shipment): void
    {
        event(DeliveryDispatchUpdated::forShipment($this->countryContext->current()->code, $event, $shipment));
    }

    private function guardCarrier(Shipment $shipment, ?Carrier $actingCarrier): void
    {
        if ($actingCarrier !== null && $shipment->carrier_id !== $actingCarrier->id) {
            throw new TransitionRefused(__('This shipment belongs to another company.'));
        }
    }

    private function newCode(): string
    {
        $length = $this->settings->codeLength();

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
