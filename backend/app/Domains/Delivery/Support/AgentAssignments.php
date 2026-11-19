<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What the delivery agent does with their own assignment (SRS §4.6, ADR 15,
 * role-permission matrix §9): accept or decline, start (picked up at the
 * seller / warehouse → out for delivery), arrive, confirm delivery when the
 * code the customer reads aloud matches the one on screen ("Codes match"
 * — the agent never types it), with proof photo / signature for COD and
 * high-value parcels, or fail with a reason (→ reschedule or return to
 * origin). The caller has already checked the assignment is the agent's.
 */
class AgentAssignments
{
    public const DISK = 'local';

    public function __construct(
        private StateMachine $machine,
        private SubOrderStateMachine $subOrders,
        private DeliveryDispatch $dispatch,
        private DeliverySettings $settings,
        private CountryContext $countryContext,
        private DeliveryNotices $notices,
    ) {}

    public function accept(DeliveryAssignment $assignment, int $agentUserId): DeliveryAssignment
    {
        $this->machine->move($assignment, AssignmentStatus::Accepted, ActorType::Agent, $agentUserId, null, ['accepted_at' => now()]);
        $this->dispatch->broadcastAssignment('accepted', $assignment);

        return $assignment;
    }

    /**
     * Declined work goes back to the unassigned queue (the shipment stays assignable).
     */
    public function decline(DeliveryAssignment $assignment, int $agentUserId, string $reason): DeliveryAssignment
    {
        $this->machine->move($assignment, AssignmentStatus::Declined, ActorType::Agent, $agentUserId, $reason, ['decline_reason' => mb_substr($reason, 0, 255)]);
        $this->dispatch->broadcastAssignment('declined', $assignment);

        return $assignment;
    }

    /**
     * Picked up and on the way: shipment → out for delivery (one more
     * attempt), sub-order → shipped → out for delivery; the customer is told
     * their delivery code.
     */
    public function start(DeliveryAssignment $assignment, int $agentUserId): DeliveryAssignment
    {
        $this->guardOpen($assignment);

        DB::connection($assignment->getConnectionName())->transaction(function () use ($assignment, $agentUserId): void {
            $this->machine->move($assignment, AssignmentStatus::PickedUp, ActorType::Agent, $agentUserId, null, ['picked_up_at' => now()]);
            $shipment = $assignment->shipment()->firstOrFail();

            if ($shipment->status === ShipmentStatus::LabelCreated) {
                $this->machine->move($shipment, ShipmentStatus::PickedUp, ActorType::Agent, $agentUserId);
            }

            $this->machine->move($shipment, ShipmentStatus::OutForDelivery, ActorType::Agent, $agentUserId, null, ['attempts' => $shipment->attempts + 1]);
            $subOrder = $assignment->subOrder()->firstOrFail();

            if ($subOrder->status === SubOrderStatus::Packed) {
                $this->subOrders->transition($subOrder, SubOrderStatus::Shipped, ActorType::System, null, __('Picked up by the delivery agent'));
            }

            if ($subOrder->status === SubOrderStatus::Shipped) {
                $this->subOrders->transition($subOrder, SubOrderStatus::OutForDelivery, ActorType::System, null, __('Out for delivery'));
            }

            $assignment->forceFill(['cod_amount' => $this->dispatch->expectedCod($subOrder)])->save();
        });

        $this->dispatch->broadcastAssignment('picked_up', $assignment);
        $this->notices->assignment('delivery.out_for_delivery', $assignment);

        return $assignment;
    }

    /**
     * At the customer's door (time recorded once; no status change).
     */
    public function arrive(DeliveryAssignment $assignment): DeliveryAssignment
    {
        if ($assignment->status !== AssignmentStatus::PickedUp) {
            throw new TransitionRefused(__('Start the delivery before arriving.'));
        }

        if ($assignment->arrived_at === null) {
            $assignment->forceFill(['arrived_at' => now()])->save();
            $this->dispatch->broadcastAssignment('arrived', $assignment);
        }

        return $assignment;
    }

    /**
     * Store a proof photo and / or signature (file references on the private disk).
     */
    public function attachProof(DeliveryAssignment $assignment, ?UploadedFile $photo = null, ?UploadedFile $signature = null, ?string $signatureData = null): DeliveryAssignment
    {
        $this->guardOpen($assignment);

        if ($assignment->status !== AssignmentStatus::PickedUp) {
            throw new TransitionRefused(__('Proof can be added while the delivery is in progress.'));
        }

        $folder = 'delivery-proofs/'.$this->countryContext->current()->code.'/'.$assignment->number;
        $changes = [];

        if ($photo !== null) {
            $changes['proof_photo_path'] = $photo->store($folder, self::DISK);
        }

        if ($signature !== null) {
            $changes['signature_path'] = $signature->store($folder, self::DISK);
        } elseif ($signatureData !== null) {
            $changes['signature_path'] = $this->storeSignatureData($folder, $signatureData);
        }

        if ($changes !== []) {
            foreach (['proof_photo_path', 'signature_path'] as $column) {
                if (isset($changes[$column]) && $assignment->{$column} !== null) {
                    Storage::disk(self::DISK)->delete($assignment->{$column});
                }
            }

            $assignment->forceFill($changes)->save();
        }

        return $assignment;
    }

    /**
     * "Codes match": the customer read their code aloud and it matched the
     * agent's screen. Delivers the shipment and the sub-order (the COD cash
     * is recorded against this agent).
     */
    public function confirmDelivery(DeliveryAssignment $assignment, int $agentUserId, bool $codesMatch): DeliveryAssignment
    {
        if (! $codesMatch) {
            throw new TransitionRefused(__('Ask the customer to read their code aloud and tap "Codes match" only when it matches.'));
        }

        $this->guardOpen($assignment);

        if ($assignment->status !== AssignmentStatus::PickedUp) {
            throw new TransitionRefused(__('This delivery is not in progress.'));
        }

        $this->guardProof($assignment);

        DB::connection($assignment->getConnectionName())->transaction(function () use ($assignment, $agentUserId): void {
            $this->machine->move($assignment, AssignmentStatus::Delivered, ActorType::Agent, $agentUserId, __('Codes match'), ['delivered_at' => now()]);
            $shipment = $assignment->shipment()->firstOrFail();
            $this->machine->move($shipment, ShipmentStatus::Delivered, ActorType::Agent, $agentUserId, null, ['delivered_at' => now()]);
            $subOrder = $assignment->subOrder()->firstOrFail();

            if (in_array(SubOrderStatus::Delivered, $subOrder->status->allowedNext(), true)) {
                $this->subOrders->transition($subOrder, SubOrderStatus::Delivered, ActorType::Agent, $agentUserId, __('Delivery code matched'), $agentUserId);
            }

            $collection = CodCollection::query()->where('sub_order_id', $subOrder->id)->first();

            if ($collection !== null) {
                $assignment->forceFill(['cod_amount' => $collection->amount])->save();
            }
        });

        $this->dispatch->broadcastAssignment('delivered', $assignment);
        $this->notices->assignment('delivery.delivered', $assignment);

        return $assignment;
    }

    /**
     * Can't deliver: a configured reason is required. The shipment fails;
     * with no attempts left or when the customer refused, it goes back to
     * origin automatically, otherwise it waits for a reschedule.
     */
    public function fail(DeliveryAssignment $assignment, int $agentUserId, string $reason, ?string $note = null): DeliveryAssignment
    {
        if (! in_array($reason, $this->settings->failureReasons(), true)) {
            throw new TransitionRefused(__('Pick one of the listed reasons.'));
        }

        $shipment = DB::connection($assignment->getConnectionName())->transaction(function () use ($assignment, $agentUserId, $reason, $note) {
            $this->machine->move($assignment, AssignmentStatus::Failed, ActorType::Agent, $agentUserId, $reason, [
                'failed_at' => now(), 'failure_reason' => $reason, 'failure_note' => $note === null ? null : mb_substr($note, 0, 255),
            ]);
            $shipment = $assignment->shipment()->firstOrFail();

            return $this->machine->move($shipment, ShipmentStatus::Failed, ActorType::Agent, $agentUserId, $reason, ['last_failure_reason' => $reason]);
        });

        $this->dispatch->broadcastAssignment('failed', $assignment);

        if ($reason === 'refused' || $shipment->attempts >= $this->settings->maxAttempts()) {
            $this->dispatch->returnToOrigin($shipment, ActorType::System, null, $reason === 'refused' ? __('Customer refused the parcel') : __('Maximum delivery attempts reached'));
        }

        $this->notices->assignment('delivery.failed', $assignment);

        return $assignment;
    }

    /**
     * Proof is needed for COD parcels (`delivery.proof_required_for_cod`) and
     * from `delivery_proof_required_above`; `delivery.proof_type` says which.
     */
    public function needsProof(DeliveryAssignment $assignment): bool
    {
        $subOrder = $assignment->subOrder()->firstOrFail();
        $isCod = $subOrder->order()->value('payment_method') === PaymentMethod::Cod->value;
        $threshold = $this->settings->proofRequiredAbove();

        return ($isCod && $this->settings->proofRequiredForCod())
            || ($threshold !== null && bccomp((string) $subOrder->total, $threshold, 4) >= 0);
    }

    /**
     * A cancelled assignment, or one whose sub-order is cancelled or already
     * closed (completed / returned / refunded), is never started, given
     * proof or delivered (QA-005, RT-001, RT-011). The sub-order status is
     * read through the model so the enum cast is compared as an enum.
     */
    public function guardOpen(DeliveryAssignment $assignment): void
    {
        if ($assignment->status === AssignmentStatus::Cancelled) {
            throw new TransitionRefused(__('This delivery was cancelled. Return the parcel to the origin.'));
        }

        $this->guardSubOrderOpen($assignment);
    }

    private function guardSubOrderOpen(DeliveryAssignment $assignment): void
    {
        $status = $assignment->subOrder()->first(['id', 'status'])?->status;

        if ($status === SubOrderStatus::Cancelled) {
            throw new TransitionRefused(__('This order was cancelled. Return the parcel to the origin.'));
        }

        if (in_array($status, [SubOrderStatus::Completed, SubOrderStatus::Returned, SubOrderStatus::Refunded], true)) {
            throw new TransitionRefused(__('This order is already closed.'));
        }
    }

    private function guardProof(DeliveryAssignment $assignment): void
    {
        if (! $this->needsProof($assignment)) {
            return;
        }

        $missing = match ($this->settings->proofType()) {
            'photo' => $assignment->proof_photo_path === null,
            'signature' => $assignment->signature_path === null,
            default => ! $assignment->hasProof(),
        };

        if ($missing) {
            throw new TransitionRefused(match ($this->settings->proofType()) {
                'photo' => __('Take a proof photo before confirming this delivery.'),
                'signature' => __('Collect the customer\'s signature before confirming this delivery.'),
                default => __('Add a proof photo or the customer\'s signature before confirming this delivery.'),
            });
        }
    }

    /**
     * A signature drawn on the screen (PNG data URL from the signature pad).
     */
    private function storeSignatureData(string $folder, string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $parts)) {
            throw new TransitionRefused(__('The signature could not be read.'));
        }

        $binary = base64_decode($parts[1], true);

        if ($binary === false || ! str_starts_with($binary, "\x89PNG") || strlen($binary) > $this->settings->proofMaxKilobytes() * 1024) {
            throw new TransitionRefused(__('The signature could not be read.'));
        }

        $path = $folder.'/signature-'.Str::random(12).'.png';
        Storage::disk(self::DISK)->put($path, $binary);

        return $path;
    }
}
