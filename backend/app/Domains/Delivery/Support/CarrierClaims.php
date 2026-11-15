<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\ClaimStatus;
use App\Domains\Delivery\Enums\ClaimType;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\CarrierClaim;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Finance\Support\Wallets;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\SubOrder;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Carrier claims (SRS §4.1.11, lifecycle catalogue §6.4): a lost or damaged
 * shipment → submitted → under review → approved (amount ≤ claimed) |
 * rejected (reason) → compensated: Dr carrier receivable / Cr the seller's
 * spendable balance or the customer's Nawy Credits. Claims feed the
 * carrier scorecard.
 */
class CarrierClaims
{
    public function __construct(
        private StateMachine $machine,
        private MoneyFactory $money,
        private Ledger $ledger,
        private Wallets $wallets,
        private CountryContext $countryContext,
    ) {}

    public function open(SubOrder $subOrder, ClaimType $type, string $description, Money $claimed, string $compensateTo, ActorType $actorType, ?int $actorUserId): CarrierClaim
    {
        $shipment = Shipment::query()->where('sub_order_id', $subOrder->id)->first();

        if ($shipment === null || $shipment->carrier_id === null) {
            throw new TransitionRefused(__('This order was not handled by a carrier.'));
        }

        $limit = $this->money->of((string) $subOrder->total, $claimed->getCurrency()->getCurrencyCode());

        if (! $claimed->isPositive() || $claimed->isGreaterThan($limit)) {
            throw new TransitionRefused(__('The claimed amount must be above zero and at most the order value.'));
        }

        if (! in_array($compensateTo, [CarrierClaim::COMPENSATE_VENDOR, CarrierClaim::COMPENSATE_CUSTOMER], true)) {
            throw new TransitionRefused(__('Choose who is compensated.'));
        }

        return DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $shipment, $type, $description, $claimed, $compensateTo, $actorType, $actorUserId): CarrierClaim {
            $claim = CarrierClaim::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'carrier_id' => $shipment->carrier_id,
                'shipment_id' => $shipment->id,
                'sub_order_id' => $subOrder->id,
                'type' => $type,
                'description' => mb_substr($description, 0, 1000),
                'currency' => $claimed->getCurrency()->getCurrencyCode(),
                'claimed_amount' => $this->money->toStorage($claimed),
                'compensate_to' => $compensateTo,
                'status' => ClaimStatus::Submitted,
                'raised_by_type' => $actorType->value,
                'raised_by_user_id' => $actorUserId,
            ]);
            $claim->forceFill(['number' => 'CLM-'.$claim->id.'-'.$subOrder->number])->save();
            $this->machine->created($claim, $actorType, $actorUserId);

            return $claim;
        });
    }

    public function review(CarrierClaim $claim, int $adminUserId): CarrierClaim
    {
        return $this->machine->move($claim, ClaimStatus::UnderReview, ActorType::Admin, $adminUserId);
    }

    public function approve(CarrierClaim $claim, Money $approved, int $adminUserId, ?string $note = null): CarrierClaim
    {
        $claimed = $this->money->of((string) $claim->claimed_amount, $claim->currency);

        if (! $approved->isPositive() || $approved->isGreaterThan($claimed)) {
            throw new TransitionRefused(__('The approved amount must be above zero and at most the claimed amount.'));
        }

        return $this->machine->move($claim, ClaimStatus::Approved, ActorType::Admin, $adminUserId, $note, [
            'approved_amount' => $this->money->toStorage($approved),
            'decision_note' => $note,
        ]);
    }

    public function reject(CarrierClaim $claim, int $adminUserId, string $note): CarrierClaim
    {
        return $this->machine->move($claim, ClaimStatus::Rejected, ActorType::Admin, $adminUserId, $note, ['decision_note' => mb_substr($note, 0, 255)]);
    }

    /**
     * Pay the approved amount out to the seller or the customer, owed by the carrier.
     */
    public function compensate(CarrierClaim $claim, ActorType $actorType, int $actorUserId): CarrierClaim
    {
        return DB::connection($claim->getConnectionName())->transaction(function () use ($claim, $actorType, $actorUserId): CarrierClaim {
            $this->machine->move($claim, ClaimStatus::Compensated, $actorType, $actorUserId, null, ['compensated_at' => now()]);
            $amount = $this->money->of((string) $claim->approved_amount, $claim->currency);
            $subOrder = $claim->subOrder()->firstOrFail();
            $carrierAccount = AccountRef::carrierReceivable($claim->carrier_id);

            if ($claim->compensate_to === CarrierClaim::COMPENSATE_CUSTOMER) {
                $this->wallets->credit((int) $subOrder->order()->value('customer_user_id'), $amount, "carrier-claim:{$claim->id}", 'carrier_claim', $carrierAccount, $claim, $actorType, $actorUserId, "Carrier claim {$claim->number}");
            } else {
                $this->ledger->post("carrier-claim:{$claim->id}", 'carrier_claim', (new Posting)
                    ->debit($carrierAccount, $amount)
                    ->credit(AccountRef::vendor($subOrder->vendor_id), $amount), $actorType, $actorUserId, $claim, "Carrier claim {$claim->number}");
            }

            return $claim;
        });
    }

    public function carrierFor(CarrierClaim $claim): Carrier
    {
        return $claim->carrier()->firstOrFail();
    }
}
