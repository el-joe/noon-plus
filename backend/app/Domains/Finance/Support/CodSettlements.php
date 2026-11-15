<?php

namespace App\Domains\Finance\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Enums\CodSettlementStatus;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Finance\Models\CodSettlement;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentStateMachine;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cash on delivery money (SRS §8, lifecycle catalogue §3.4).
 *
 * - {@see recordDelivery()} is the hook the delivery flow calls when a COD
 *   sub-order is delivered (today from the sub-order machine; WP7 passes
 *   the agent): Dr COD receivable (agent) / Cr order liability. When
 *   nothing of the order is left to deliver, the COD payment is captured.
 * - {@see generate()} (GenerateCodSettlementsJob, daily 23:30) groups the
 *   unsettled collections per agent for the day.
 * - {@see settle()}: remitted = expected → settled (Dr bank / Cr COD
 *   receivable), the sub-orders get `cod_remittance_confirmed` and their
 *   vendor settlements move from pending to spendable — the payout unlocks.
 *   A different amount → disputed, resolved by an admin later.
 */
class CodSettlements
{
    public function __construct(
        private Ledger $ledger,
        private MoneyFactory $money,
        private StateMachine $machine,
    ) {}

    /**
     * The order's COD fee is collected once (QA-006): on its first parcel
     * that is not cancelled, refunded or returned (lowest id), unless a
     * collection already carried it — so the parcels' cash adds up to the
     * order total even when several agents are on the way at once.
     */
    public function carriesCodFee(SubOrder $subOrder): bool
    {
        if (CodCollection::query()->where('order_id', $subOrder->order_id)->where('cod_fee', '>', 0)->exists()) {
            return false;
        }

        $carrierId = SubOrder::query()->where('order_id', $subOrder->order_id)
            ->whereNotIn('status', [SubOrderStatus::Cancelled, SubOrderStatus::Refunded, SubOrderStatus::Returned])
            ->min('id');

        return $carrierId === null || (int) $carrierId === $subOrder->id;
    }

    public function recordDelivery(SubOrder $subOrder, ?int $agentUserId = null): ?CodCollection
    {
        $order = $subOrder->order()->firstOrFail();

        if ($order->payment_method !== PaymentMethod::Cod->value) {
            return null;
        }

        return DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $order, $agentUserId): CodCollection {
            $existing = CodCollection::query()->where('sub_order_id', $subOrder->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $currency = $order->currency;
            $codFee = $this->carriesCodFee($subOrder) ? $this->money->of((string) $order->cod_fee, $currency) : $this->money->zero($currency);
            $amount = $this->money->of((string) $subOrder->total, $currency)->plus($this->money->of((string) $subOrder->warranty_total, $currency))->plus($codFee);

            $collection = CodCollection::query()->create([
                'sub_order_id' => $subOrder->id,
                'order_id' => $order->id,
                'agent_user_id' => $agentUserId,
                'currency' => $currency,
                'amount' => $this->money->toStorage($amount),
                'cod_fee' => $this->money->toStorage($codFee),
                'collected_at' => now(),
            ]);

            if ($amount->isPositive()) {
                $this->ledger->post("cod-collection:{$subOrder->id}", 'cod_collection', (new Posting)
                    ->debit(AccountRef::codReceivable($agentUserId), $amount)
                    ->credit(AccountRef::platform(LedgerAccountCode::OrderLiability), $amount), reference: $collection, description: "COD collected for {$subOrder->number}");
            }

            $this->captureWhenAllDelivered($order->id);

            return $collection;
        });
    }

    /**
     * Group unsettled collections per agent into today's settlement (country
     * local date); running again the same day adds to the pending one.
     */
    public function generate(Country $country): int
    {
        $businessDate = now($country->timezone)->toDateString();
        $created = 0;

        CodCollection::query()->whereNull('cod_settlement_id')->where('collected_at', '<=', now())->get()
            ->groupBy(fn (CodCollection $collection): string => $collection->agent_user_id === null ? 'unassigned' : 'agent-'.$collection->agent_user_id)
            ->each(function ($collections, string $agentKey) use ($businessDate, $country, &$created): void {
                DB::connection($collections->first()->getConnectionName())->transaction(function () use ($collections, $agentKey, $businessDate, $country, &$created): void {
                    $settlement = CodSettlement::query()->where('agent_key', $agentKey)->whereDate('business_date', $businessDate)->lockForUpdate()->first();

                    if ($settlement !== null && $settlement->status !== CodSettlementStatus::Pending) {
                        return;
                    }

                    if ($settlement === null) {
                        $settlement = CodSettlement::query()->create([
                            'number' => 'PENDING-'.uniqid(),
                            'agent_user_id' => $collections->first()->agent_user_id,
                            'agent_key' => $agentKey,
                            'business_date' => $businessDate,
                            'currency' => $country->currency,
                            'expected_amount' => '0',
                            'collections_count' => 0,
                            'status' => CodSettlementStatus::Pending,
                        ]);
                        $settlement->forceFill(['number' => 'COD-'.str_replace('-', '', $businessDate).'-'.$settlement->id])->save();
                        $this->machine->created($settlement, ActorType::System);
                        $created++;
                    }

                    CodCollection::query()->whereKey($collections->pluck('id'))->update(['cod_settlement_id' => $settlement->id]);
                    $expected = $this->money->of((string) $settlement->collections()->sum('amount'), $country->currency);
                    $settlement->forceFill(['expected_amount' => $this->money->toStorage($expected), 'collections_count' => $settlement->collections()->count()])->save();
                });
            });

        return $created;
    }

    /**
     * Admin marks the remitted cash. Equal to expected → settled and the
     * vendor payouts unlock; otherwise → disputed with the variance.
     * A disputed settlement can be settled with the cash received (the
     * shortfall stays on the agent's COD receivable).
     */
    public function settle(CodSettlement $settlement, Money $remitted, int $adminUserId, ?string $note = null): CodSettlement
    {
        $expected = $this->money->of((string) $settlement->expected_amount, $settlement->currency);

        if ($remitted->isNegative()) {
            throw new TransitionRefused(__('The remitted amount cannot be negative.'));
        }

        if (! $remitted->isEqualTo($expected) && $settlement->status === CodSettlementStatus::Pending) {
            return $this->machine->move($settlement, CodSettlementStatus::Disputed, ActorType::Admin, $adminUserId, $note ?? __('Variance :amount', ['amount' => (string) $remitted->minus($expected)->getAmount()]), [
                'remitted_amount' => $this->money->toStorage($remitted),
                'note' => $note,
            ]);
        }

        return DB::connection($settlement->getConnectionName())->transaction(function () use ($settlement, $remitted, $adminUserId, $note): CodSettlement {
            $this->machine->move($settlement, CodSettlementStatus::Settled, ActorType::Admin, $adminUserId, $note, [
                'remitted_amount' => $this->money->toStorage($remitted),
                'settled_at' => now(),
                'note' => $note ?? $settlement->note,
            ]);

            if ($remitted->isPositive()) {
                $this->ledger->post("cod-settlement:{$settlement->id}", 'cod_settlement', (new Posting)
                    ->debit(AccountRef::platform(LedgerAccountCode::BankClearing), $remitted)
                    ->credit(AccountRef::codReceivable($settlement->agent_user_id), $remitted), ActorType::Admin, $adminUserId, $settlement, "COD settlement {$settlement->number}");
            }

            $subOrderIds = $settlement->collections()->pluck('sub_order_id');
            SubOrder::query()->whereKey($subOrderIds)->update(['cod_remittance_confirmed' => true]);
            $this->unlockVendorBalances($subOrderIds->all(), $adminUserId);

            return $settlement->refresh();
        });
    }

    /**
     * Settled vendor money of these sub-orders: pending → spendable.
     *
     * @param  array<int, int>  $subOrderIds
     */
    private function unlockVendorBalances(array $subOrderIds, int $adminUserId): void
    {
        $settlementIds = SubOrderSettlement::query()->whereIn('sub_order_id', $subOrderIds)->pluck('id');

        VendorBalanceItem::query()
            ->where('type', VendorBalanceItem::TYPE_SETTLEMENT)
            ->where('reference_type', 'sub_order_settlement')
            ->whereIn('reference_id', $settlementIds)
            ->where('is_eligible', false)
            ->get()
            ->each(function (VendorBalanceItem $item) use ($adminUserId): void {
                $settlement = SubOrderSettlement::query()->findOrFail($item->reference_id);
                $net = $this->money->of((string) $item->amount, $settlement->currency);
                $this->ledger->post("cod-unlock:{$item->id}", 'cod_unlock', (new Posting)
                    ->debit(AccountRef::vendor($item->vendor_id, WalletBucket::Pending), $net)
                    ->credit(AccountRef::vendor($item->vendor_id, WalletBucket::Spendable), $net), ActorType::Admin, $adminUserId, $settlement, 'COD remitted — payout unlocked');
                $item->forceFill(['is_eligible' => true])->save();
            });
    }

    /**
     * A COD sub-order was cancelled (QA-016): once nothing of the order is
     * on its way, the pending COD payment is captured for what was
     * collected, or voided when nothing was.
     */
    public function syncAfterCancellation(int $orderId): void
    {
        $this->captureWhenAllDelivered($orderId);
    }

    /**
     * Capture the COD payment once no sub-order of the order is still on
     * its way (each delivered one was collected); void it when nothing was
     * collected (every parcel cancelled).
     */
    private function captureWhenAllDelivered(int $orderId): void
    {
        $onTheWay = SubOrder::query()->where('order_id', $orderId)->whereIn('status', [
            SubOrderStatus::Placed, SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed,
            SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery,
        ])->exists();

        if ($onTheWay) {
            return;
        }

        $payment = Payment::query()->where('order_id', $orderId)->where('method', PaymentMethod::Cod)->where('status', PaymentStatus::Pending)->latest('id')->first();

        if ($payment === null) {
            return;
        }

        $collected = $this->money->of((string) CodCollection::query()->where('order_id', $orderId)->sum('amount'), $payment->currency);

        if (! CodCollection::query()->where('order_id', $orderId)->exists()) {
            app(PaymentStateMachine::class)->transition($payment, PaymentStatus::Voided, ActorType::System, null, 'cod', 'Order cancelled before delivery: nothing to collect');

            return;
        }

        app(PaymentStateMachine::class)->transition($payment, PaymentStatus::Captured, ActorType::System, null, 'cod', 'Cash collected on delivery', [
            'captured_amount' => $this->money->toStorage($collected),
        ]);
    }
}
