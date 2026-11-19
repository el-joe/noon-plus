<?php

namespace App\Domains\Finance\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\PayoutSchedule;
use App\Domains\Finance\Enums\PayoutStatus;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Models\VendorPayout;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\PartnerEmails;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Vendor payouts (SRS §9, lifecycle catalogue §3.1).
 *
 * Generation (weekly Monday / biweekly 1st & 15th / monthly 1st per vendor
 * `payout_schedule`, country local date): every eligible balance item not
 * yet on a statement — settled sub-orders (COD only once remitted) and
 * clawbacks — goes on one pending statement when the total is positive and
 * at least `payout.minimum_amount`. The total moves from the vendor's
 * spendable to reserved bucket. Admin approve (four-eyes: not the admin
 * who generated it) → process → completed (Dr vendor reserved / Cr bank)
 * or failed (retry); pending ⇄ on hold; recalculate adds newly eligible
 * items. Nothing is paid automatically.
 */
class Payouts
{
    public function __construct(
        private Ledger $ledger,
        private MoneyFactory $money,
        private StateMachine $machine,
        private FinanceSettings $settings,
    ) {}

    /**
     * Daily 02:00 run (QC-069): each vendor gets a statement only on the
     * days its own `payout_schedule` is due in the country's time zone
     * (weekly → Monday, biweekly → 1st and 15th, monthly → 1st), which is
     * how the SRS schedules share one job. `$ignoreSchedule` is the admin's
     * out-of-schedule run.
     *
     * @return int number of statements created
     */
    public function generate(Country $country, bool $ignoreSchedule = false, ?int $adminUserId = null): int
    {
        $today = CarbonImmutable::now($country->timezone)->startOfDay();
        $created = 0;

        Vendor::query()->whereIn('id', VendorBalanceItem::query()->where('is_eligible', true)->whereNull('vendor_payout_id')->select('vendor_id'))
            ->orderBy('id')
            ->each(function (Vendor $vendor) use ($country, $today, $ignoreSchedule, $adminUserId, &$created): void {
                $schedule = PayoutSchedule::tryFrom((string) $vendor->payout_schedule) ?? PayoutSchedule::Weekly;

                if (! $ignoreSchedule && ! $schedule->isDue($today)) {
                    return;
                }

                if ($this->generateFor($vendor, $country, $schedule, $today, $adminUserId) !== null) {
                    $created++;
                }
            });

        return $created;
    }

    public function generateFor(Vendor $vendor, Country $country, PayoutSchedule $schedule, CarbonImmutable $periodEnd, ?int $adminUserId = null): ?VendorPayout
    {
        return DB::connection($vendor->getConnectionName())->transaction(function () use ($vendor, $country, $schedule, $periodEnd, $adminUserId): ?VendorPayout {
            if (VendorPayout::query()->where('vendor_id', $vendor->id)->whereDate('period_end', $periodEnd->toDateString())->exists()) {
                return null;
            }

            $items = $this->unpaidItems($vendor->id)->lockForUpdate()->get();
            $total = $this->money->of((string) $items->sum('amount'), $country->currency);
            $minimum = $this->money->of((string) $this->settings->get('payout.minimum_amount', 'payout_minimum_amount'), $country->currency);

            if ($items->isEmpty() || ! $total->isPositive() || $total->isLessThan($minimum)) {
                return null;
            }

            $previousEnd = VendorPayout::query()->where('vendor_id', $vendor->id)->max('period_end');
            $payout = VendorPayout::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'vendor_id' => $vendor->id,
                'currency' => $country->currency,
                'status' => PayoutStatus::Pending,
                'schedule' => $schedule,
                'period_start' => $previousEnd !== null ? CarbonImmutable::parse($previousEnd)->addDay()->toDateString() : $items->min('created_at')->setTimezone($country->timezone)->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'total' => $this->money->toStorage($total),
                'generated_by_user_id' => $adminUserId,
            ]);
            $payout->forceFill(['number' => sprintf('ST-%s-%05d', $periodEnd->format('Y'), $payout->id)])->save();
            VendorBalanceItem::query()->whereKey($items->pluck('id'))->update(['vendor_payout_id' => $payout->id]);
            $this->reserve($payout, $total, 'generate');
            $this->machine->created($payout, $adminUserId === null ? ActorType::System : ActorType::Admin, $adminUserId);
            app(PartnerEmails::class)->toVendor($vendor->id, 'payout.statement', [
                'payout_number' => $payout->number,
                'period' => $payout->period_start->toDateString().' – '.$payout->period_end->toDateString(),
                'amount' => EmailValue::money((string) $payout->total, $payout->currency),
            ], '/payouts/'.$payout->id, ['entity' => $payout]);

            return $payout;
        });
    }

    /**
     * Add newly eligible items to a pending statement (history entry).
     */
    public function recalculate(VendorPayout $payout, int $adminUserId): VendorPayout
    {
        if ($payout->status !== PayoutStatus::Pending) {
            throw new TransitionRefused(__('Only pending statements can be recalculated.'));
        }

        return DB::connection($payout->getConnectionName())->transaction(function () use ($payout, $adminUserId): VendorPayout {
            $items = $this->unpaidItems($payout->vendor_id)->lockForUpdate()->get();
            $added = $this->money->of((string) $items->sum('amount'), $payout->currency);
            VendorBalanceItem::query()->whereKey($items->pluck('id'))->update(['vendor_payout_id' => $payout->id]);
            $total = $this->money->of((string) $payout->total, $payout->currency)->plus($added);

            if (! $added->isZero()) {
                $this->reserve($payout, $added, 'recalculate:'.$payout->transitions()->count());
            }

            return $this->machine->move($payout, PayoutStatus::Pending, ActorType::Admin, $adminUserId, __('Recalculated: :count new lines', ['count' => $items->count()]), [
                'total' => $this->money->toStorage($total),
            ]);
        });
    }

    public function approve(VendorPayout $payout, int $adminUserId): VendorPayout
    {
        if ((bool) $this->settings->get('payout.require_four_eyes', 'payout_require_four_eyes') && $payout->generated_by_user_id === $adminUserId) {
            throw new TransitionRefused(__('Another admin must approve a statement you generated.'));
        }

        return $this->machine->move($payout, PayoutStatus::Approved, ActorType::Admin, $adminUserId, null, [
            'approved_by_user_id' => $adminUserId,
            'approved_at' => now(),
        ]);
    }

    public function hold(VendorPayout $payout, int $adminUserId, string $reason): VendorPayout
    {
        return $this->machine->move($payout, PayoutStatus::OnHold, ActorType::Admin, $adminUserId, $reason, ['hold_reason' => mb_substr($reason, 0, 255)]);
    }

    public function release(VendorPayout $payout, int $adminUserId): VendorPayout
    {
        return $this->machine->move($payout, PayoutStatus::Pending, ActorType::Admin, $adminUserId, 'Released from hold', ['hold_reason' => null]);
    }

    /**
     * approved | failed → processing (bank transfer sent).
     */
    public function process(VendorPayout $payout, int $adminUserId): VendorPayout
    {
        return $this->machine->move($payout, PayoutStatus::Processing, ActorType::Admin, $adminUserId, null, ['failure_reason' => null]);
    }

    public function fail(VendorPayout $payout, int $adminUserId, string $reason): VendorPayout
    {
        return $this->machine->move($payout, PayoutStatus::Failed, ActorType::Admin, $adminUserId, $reason, ['failure_reason' => mb_substr($reason, 0, 255)]);
    }

    /**
     * Bank confirmed: Dr vendor reserved / Cr bank clearing.
     */
    public function complete(VendorPayout $payout, int $adminUserId, string $bankReference): VendorPayout
    {
        return DB::connection($payout->getConnectionName())->transaction(function () use ($payout, $adminUserId, $bankReference): VendorPayout {
            $this->machine->move($payout, PayoutStatus::Completed, ActorType::Admin, $adminUserId, null, [
                'bank_reference' => mb_substr($bankReference, 0, 100),
                'completed_at' => now(),
            ]);
            $total = $this->money->of((string) $payout->total, $payout->currency);
            $this->ledger->post("payout:{$payout->id}:paid", 'vendor_payout', (new Posting)
                ->debit(AccountRef::vendor($payout->vendor_id, WalletBucket::Reserved), $total)
                ->credit(AccountRef::platform(LedgerAccountCode::BankClearing), $total), ActorType::Admin, $adminUserId, $payout, "Payout {$payout->number}");

            return $payout;
        });
    }

    private function reserve(VendorPayout $payout, Money $amount, string $step): void
    {
        $this->ledger->post("payout:{$payout->id}:{$step}", 'payout_reserve', (new Posting)
            ->debit(AccountRef::vendor($payout->vendor_id, WalletBucket::Spendable), $amount)
            ->credit(AccountRef::vendor($payout->vendor_id, WalletBucket::Reserved), $amount), reference: $payout, description: "Statement {$payout->number}");
    }

    /**
     * @return Builder<VendorBalanceItem>
     */
    private function unpaidItems(int $vendorId): Builder
    {
        return VendorBalanceItem::query()->where('vendor_id', $vendorId)->where('is_eligible', true)->whereNull('vendor_payout_id')->orderBy('id');
    }
}
