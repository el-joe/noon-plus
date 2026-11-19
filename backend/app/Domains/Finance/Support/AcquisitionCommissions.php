<?php

namespace App\Domains\Finance\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\AcquisitionCommissionStatus;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\AcquisitionCommission;
use App\Domains\Finance\Models\AcquisitionCommissionRule;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Support\PanelNotifications;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Acquisition commissions (FR-FIN-6, SRS §4.1.5, §9). Monthly (1st 02:00
 * admin time, previous month): for every vendor with an acquirer
 * (`vendors.acquired_by_user_id`), the month's settled sales (gross − seller
 * coupons − refunds of sub-orders settled in the country-local month) are
 * matched to the country's active rules — the acquirer's own rules first,
 * then the general ones — whose date range overlaps the month and whose
 * monthly min/max band contains the sales; the commission is the rule rate
 * × sales. One row per vendor and month (idempotent), accrued at once
 * (Dr acquisition commission expense / Cr staff payable). A finance admin
 * who is not the acquirer approves it; paying it by bank transfer posts
 * Dr staff payable / Cr bank clearing; rejecting reverses the accrual.
 */
class AcquisitionCommissions
{
    public function __construct(
        private StateMachine $machine,
        private Ledger $ledger,
        private MoneyFactory $money,
        private CountryContext $countryContext,
    ) {}

    /**
     * The country-local month containing `$monthDay`, as UTC bounds.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function monthBounds(CarbonImmutable $monthDay): array
    {
        $timezone = $this->countryContext->current()->timezone;
        $local = $monthDay->setTimezone($timezone);

        return [$local->startOfMonth()->utc(), $local->endOfMonth()->utc()];
    }

    /**
     * Compute the month that contains `$monthDay` for every attributed vendor.
     *
     * @return int commissions created
     */
    public function generate(CarbonImmutable $monthDay): int
    {
        [$start, $end] = $this->monthBounds($monthDay);
        $periodStart = $start->setTimezone($this->countryContext->current()->timezone)->toDateString();
        $periodEnd = $end->setTimezone($this->countryContext->current()->timezone)->toDateString();
        $rules = AcquisitionCommissionRule::query()->where('is_active', true)->orderByDesc('min_monthly_sales')->orderBy('id')->get()
            ->filter(fn (AcquisitionCommissionRule $rule): bool => $rule->coversPeriod(CarbonImmutable::parse($periodStart), CarbonImmutable::parse($periodEnd)));

        if ($rules->isEmpty()) {
            return 0;
        }

        $created = 0;

        Vendor::query()->whereNotNull('acquired_by_user_id')->orderBy('id')->each(function (Vendor $vendor) use ($start, $end, $periodStart, $periodEnd, $rules, &$created): void {
            if (AcquisitionCommission::query()->where('vendor_id', $vendor->id)->whereDate('period_start', $periodStart)->exists()) {
                return;
            }

            $settlements = SubOrderSettlement::query()->where('vendor_id', $vendor->id)->whereBetween('settled_at', [$start, $end]);
            $count = (clone $settlements)->count();
            $sales = (string) (clone $settlements)->selectRaw('COALESCE(SUM(gross - vendor_discount - refunds), 0) as sales')->value('sales');

            if ($count === 0 || bccomp($sales, '0', 4) <= 0) {
                return;
            }

            $rule = $this->ruleFor((int) $vendor->acquired_by_user_id, $sales, $rules);

            if ($rule === null) {
                return;
            }

            $currency = $this->countryContext->current()->currency;
            $amount = $this->money->of($sales, $currency)
                ->multipliedBy((string) $rule->rate_percent, RoundingMode::HalfUp)->dividedBy('100', RoundingMode::HalfUp);

            if (! $amount->isPositive()) {
                return;
            }

            DB::connection($vendor->getConnectionName())->transaction(function () use ($vendor, $rule, $periodStart, $periodEnd, $currency, $sales, $count, $amount, &$created): void {
                $commission = AcquisitionCommission::query()->create([
                    'acquirer_user_id' => $vendor->acquired_by_user_id,
                    'vendor_id' => $vendor->id,
                    'acquisition_commission_rule_id' => $rule->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'currency' => $currency,
                    'sales_amount' => $sales,
                    'sub_orders_count' => $count,
                    'rate_percent' => $rule->rate_percent,
                    'amount' => $this->money->toStorage($amount),
                    'status' => AcquisitionCommissionStatus::Pending,
                ]);
                $commission->forceFill(['number' => sprintf('ACQ-%s-%06d', strtoupper($this->countryContext->current()->iso2), $commission->id)])->save();
                $this->machine->created($commission, ActorType::System, null, "Rule {$rule->name}");
                $this->ledger->post("acquisition-commission:{$commission->id}:accrue", 'acquisition_commission', (new Posting)
                    ->debit(AccountRef::platform(LedgerAccountCode::AcquisitionCommissionExpense), $amount)
                    ->credit(AccountRef::staff((int) $vendor->acquired_by_user_id), $amount), reference: $commission, description: "Acquisition commission {$commission->number}");
                app(PanelNotifications::class)->notify((int) $vendor->acquired_by_user_id, 'admin', 'acquisition_commission.created',
                    __('Acquisition commission :number', ['number' => $commission->number]),
                    __(':store sold :sales in :month.', ['store' => $vendor->store_name, 'sales' => $sales.' '.$currency, 'month' => $periodStart]),
                    '/my-acquisition-commissions');
                $created++;
            });
        });

        return $created;
    }

    public function approve(AcquisitionCommission $commission, int $adminUserId): AcquisitionCommission
    {
        if ((int) $commission->acquirer_user_id === $adminUserId) {
            throw new TransitionRefused(__('You cannot approve your own acquisition commission.'));
        }

        return $this->machine->move($commission, AcquisitionCommissionStatus::Approved, ActorType::Admin, $adminUserId, null, [
            'approved_by' => $adminUserId,
            'approved_at' => now(),
        ]);
    }

    public function pay(AcquisitionCommission $commission, int $adminUserId, string $bankReference): AcquisitionCommission
    {
        if ((int) $commission->acquirer_user_id === $adminUserId) {
            throw new TransitionRefused(__('You cannot pay your own acquisition commission.'));
        }

        return DB::connection($commission->getConnectionName())->transaction(function () use ($commission, $adminUserId, $bankReference): AcquisitionCommission {
            $commission = $this->machine->move($commission, AcquisitionCommissionStatus::Paid, ActorType::Admin, $adminUserId, "Bank reference {$bankReference}", [
                'paid_by' => $adminUserId,
                'paid_at' => now(),
                'bank_reference' => $bankReference,
            ]);
            $amount = $this->money->of((string) $commission->amount, $commission->currency);
            $this->ledger->post("acquisition-commission:{$commission->id}:pay", 'acquisition_commission_payment', (new Posting)
                ->debit(AccountRef::staff((int) $commission->acquirer_user_id), $amount)
                ->credit(AccountRef::platform(LedgerAccountCode::BankClearing), $amount), ActorType::Admin, $adminUserId, $commission, "Acquisition commission {$commission->number} paid");

            return $commission;
        });
    }

    public function reject(AcquisitionCommission $commission, int $adminUserId, string $reason): AcquisitionCommission
    {
        return DB::connection($commission->getConnectionName())->transaction(function () use ($commission, $adminUserId, $reason): AcquisitionCommission {
            $commission = $this->machine->move($commission, AcquisitionCommissionStatus::Rejected, ActorType::Admin, $adminUserId, $reason, ['rejection_reason' => $reason]);
            $amount = $this->money->of((string) $commission->amount, $commission->currency);
            $this->ledger->post("acquisition-commission:{$commission->id}:reverse", 'acquisition_commission_reversal', (new Posting)
                ->debit(AccountRef::staff((int) $commission->acquirer_user_id), $amount)
                ->credit(AccountRef::platform(LedgerAccountCode::AcquisitionCommissionExpense), $amount), ActorType::Admin, $adminUserId, $commission, "Acquisition commission {$commission->number} rejected");

            return $commission;
        });
    }

    /**
     * @param  Collection<int, AcquisitionCommissionRule>  $rules  ordered by min sales, highest first
     */
    private function ruleFor(int $acquirerUserId, string $sales, Collection $rules): ?AcquisitionCommissionRule
    {
        $matches = $rules->filter(fn (AcquisitionCommissionRule $rule): bool => $rule->bandContains($sales));

        return $matches->first(fn (AcquisitionCommissionRule $rule): bool => (int) $rule->acquirer_user_id === $acquirerUserId)
            ?? $matches->first(fn (AcquisitionCommissionRule $rule): bool => $rule->acquirer_user_id === null);
    }
}
