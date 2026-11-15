<?php

namespace App\Domains\Warranty\Support;

use App\Domains\Finance\Support\FinanceSettings;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Enums\RefundSource;
use App\Domains\Refunds\Support\Refunds;
use App\Domains\Warranty\Enums\WarrantyClaimStatus;
use App\Domains\Warranty\Enums\WarrantyCoverage;
use App\Domains\Warranty\Enums\WarrantyOutcome;
use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Domains\Warranty\Models\WarrantyClaim;
use App\Domains\Warranty\Models\WarrantyPurchase;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Warranty claims (SRS §7.7, lifecycle catalogue §2.8). An active extended
 * plan for the item makes it a platform (admin) claim; otherwise the
 * vendor's standard warranty applies for `warranty.standard_months` after
 * delivery (12, proposed). The seller reviews standard claims, only an
 * admin handles extended ones. A refund outcome refunds one unit through
 * {@see Refunds} (key `warranty-claim:{id}`); an extended-plan refund is
 * the platform's cost, a standard one is clawed back from the vendor once
 * settled.
 */
class WarrantyClaims
{
    public function __construct(
        private StateMachine $machine,
        private Refunds $refunds,
        private MoneyFactory $money,
        private FinanceSettings $settings,
    ) {}

    public function submit(OrderItem $item, int $customerUserId, string $description): WarrantyClaim
    {
        return DB::connection($item->getConnectionName())->transaction(function () use ($item, $customerUserId, $description): WarrantyClaim {
            $subOrder = $item->subOrder()->firstOrFail();

            if ($subOrder->order()->value('customer_user_id') !== $customerUserId) {
                throw new TransitionRefused(__('This item is not yours.'));
            }

            if (! in_array($subOrder->status, [SubOrderStatus::Delivered, SubOrderStatus::Completed], true) || $subOrder->delivered_at === null) {
                throw new TransitionRefused(__('Warranty claims start once the item is delivered.'));
            }

            if (WarrantyClaim::query()->where('order_item_id', $item->id)->whereNotIn('status', [WarrantyClaimStatus::Rejected, WarrantyClaimStatus::Closed])->exists()) {
                throw new TransitionRefused(__('There is already an open claim for this item.'));
            }

            $plan = WarrantyPurchase::query()->where('order_item_id', $item->id)->where('status', WarrantyPurchaseStatus::Active)->where('expires_at', '>', now())->first();
            $standardEnds = $subOrder->delivered_at->addMonthsNoOverflow((int) $this->settings->get('warranty.standard_months', 'standard_warranty_months'));

            if ($plan === null && $standardEnds->isPast()) {
                throw new TransitionRefused(__('The warranty for this item ended on :date.', ['date' => $standardEnds->toDateString()]));
            }

            $claim = WarrantyClaim::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'order_item_id' => $item->id,
                'sub_order_id' => $subOrder->id,
                'vendor_id' => $subOrder->vendor_id,
                'warranty_purchase_id' => $plan?->id,
                'customer_user_id' => $customerUserId,
                'coverage' => $plan === null ? WarrantyCoverage::Standard : WarrantyCoverage::Extended,
                'status' => WarrantyClaimStatus::Submitted,
                'description' => $description,
            ]);
            $claim->forceFill(['number' => 'WC-'.$subOrder->number.'-'.$claim->id])->save();
            $claim->messages()->create(['author_type' => ActorType::Customer, 'author_user_id' => $customerUserId, 'body' => $description]);
            $this->machine->created($claim, ActorType::Customer, $customerUserId);

            return $claim;
        });
    }

    public function message(WarrantyClaim $claim, ActorType $author, ?int $authorUserId, string $body): WarrantyClaim
    {
        if ($claim->status === WarrantyClaimStatus::Closed) {
            throw new TransitionRefused(__('This claim is closed.'));
        }

        $claim->messages()->create(['author_type' => $author, 'author_user_id' => $authorUserId, 'body' => $body]);

        return $claim;
    }

    public function review(WarrantyClaim $claim, ActorType $actorType, ?int $actorUserId): WarrantyClaim
    {
        $this->guardHandler($claim, $actorType);

        return $this->machine->move($claim, WarrantyClaimStatus::UnderReview, $actorType, $actorUserId);
    }

    public function approve(WarrantyClaim $claim, ActorType $actorType, ?int $actorUserId): WarrantyClaim
    {
        $this->guardHandler($claim, $actorType);

        return $this->machine->move($claim, WarrantyClaimStatus::Approved, $actorType, $actorUserId);
    }

    public function reject(WarrantyClaim $claim, ActorType $actorType, ?int $actorUserId, string $reason): WarrantyClaim
    {
        $this->guardHandler($claim, $actorType);

        return $this->machine->move($claim, WarrantyClaimStatus::Rejected, $actorType, $actorUserId, $reason, ['rejection_reason' => mb_substr($reason, 0, 255)]);
    }

    public function fulfil(WarrantyClaim $claim, WarrantyOutcome $outcome, ActorType $actorType, ?int $actorUserId, ?Money $refundAmount = null): WarrantyClaim
    {
        $this->guardHandler($claim, $actorType);
        $refundId = null;

        if ($outcome === WarrantyOutcome::Refund) {
            if ($claim->status !== WarrantyClaimStatus::Approved) {
                throw new TransitionRefused(__('Approve the claim first.'));
            }

            $item = $claim->orderItem()->firstOrFail();
            $subOrder = $claim->subOrder()->firstOrFail();
            $order = $subOrder->order()->firstOrFail();
            $amount = $refundAmount ?? $this->money->of((string) $item->line_total, $order->currency)->dividedBy($item->quantity, RoundingMode::HalfUp);
            $refundId = $this->refunds->issue($order, $subOrder, $amount, RefundSource::WarrantyClaim, $claim->id, RefundDestination::Original,
                "warranty-claim:{$claim->id}", $actorType, $actorUserId, "Warranty claim {$claim->number}")->id;
        }

        return $this->machine->move($claim, $outcome->status(), $actorType, $actorUserId, null, ['outcome' => $outcome, 'refund_id' => $refundId]);
    }

    public function resolve(WarrantyClaim $claim, ActorType $actorType, ?int $actorUserId): WarrantyClaim
    {
        $this->guardHandler($claim, $actorType);

        return $this->machine->move($claim, WarrantyClaimStatus::Resolved, $actorType, $actorUserId);
    }

    public function close(WarrantyClaim $claim, ActorType $actorType, ?int $actorUserId): WarrantyClaim
    {
        return $this->machine->move($claim, WarrantyClaimStatus::Closed, $actorType, $actorUserId);
    }

    /**
     * The customer only writes in the thread; extended-plan claims are the
     * platform's, so a seller can only write in their thread too.
     */
    private function guardHandler(WarrantyClaim $claim, ActorType $actorType): void
    {
        if ($actorType === ActorType::Customer) {
            throw new TransitionRefused(__('Only the seller or Nawy can decide a claim.'));
        }

        if ($actorType === ActorType::Vendor && $claim->coverage === WarrantyCoverage::Extended) {
            throw new TransitionRefused(__('Extended warranty claims are handled by Nawy.'));
        }
    }
}
