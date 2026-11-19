<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Delivery\Enums\AgentWithdrawalStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\DeliveryPayslip;
use App\Domains\Delivery\Models\DeliveryPayslipLine;
use App\Domains\Delivery\Models\DeliveryWithdrawal;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\Settings\VerticalSettings;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Delivery agent pay (WP7 follow-up): payslips and wallet withdrawals.
 *
 * Payslips — per pay period (`delivery.payslip_period` weekly Mon–Sun or
 * monthly, the country's local dates) every finished assignment (delivered
 * or failed, by its finish time) not on a payslip yet goes on one payslip
 * at the rates of the day (`delivery.pay_per_stop`,
 * `delivery.pay_per_failed_attempt`). Issuing posts Dr delivery pay expense
 * / Cr agent wallet (spendable). No per-stop rate = no payslips (pay is
 * arranged outside Nawy).
 *
 * Withdrawals — request (≥ `delivery.withdrawal_minimum`, ≤ spendable,
 * one open at a time) moves spendable → reserved; reject / cancel move it
 * back; paid posts Dr agent reserved / Cr bank clearing (Nawy fleet) or Cr
 * carrier receivable (a carrier's driver: the carrier paid them for Nawy,
 * so it is set off against what the carrier owes). Four-eyes
 * (`delivery.withdrawal_four_eyes`): the reviewer who approved cannot mark
 * it paid. Fleet drivers are reviewed in admin, carrier drivers in the
 * carrier panel.
 */
class AgentPay
{
    public function __construct(
        private Ledger $ledger,
        private MoneyFactory $money,
        private StateMachine $machine,
        private VerticalSettings $settings,
    ) {}

    /**
     * Issue the payslips of every period that has ended (daily run).
     *
     * @return int payslips issued
     */
    public function issueDue(Country $country, ?CarbonImmutable $today = null): int
    {
        if ($this->settings->get('delivery.pay_per_stop') === null) {
            return 0;
        }

        $today = ($today ?? CarbonImmutable::now($country->timezone))->startOfDay();
        $issued = 0;

        DeliveryAgent::query()->whereIn('id', $this->unpaidAssignments()->select('delivery_agent_id'))->orderBy('id')
            ->each(function (DeliveryAgent $agent) use ($country, $today, &$issued): void {
                // Every closed period with unpaid work, oldest first.
                while (($period = $this->oldestUnpaidPeriod($agent, $country)) !== null && $period[1]->toDateString() < $today->toDateString()) {
                    if ($this->issue($agent, $country, $period[0], $period[1]) === null) {
                        break;
                    }

                    $issued++;
                }
            });

        return $issued;
    }

    /**
     * One payslip for the agent's unpaid finished assignments in the period
     * (null when there is nothing to pay). Work finished late in an
     * already paid period gets a supplementary payslip for that period.
     */
    public function issue(DeliveryAgent $agent, Country $country, CarbonImmutable $start, CarbonImmutable $end): ?DeliveryPayslip
    {
        $perStop = $this->settings->get('delivery.pay_per_stop');

        if ($perStop === null) {
            return null;
        }

        $perFailed = $this->settings->get('delivery.pay_per_failed_attempt');

        return DB::connection($agent->getConnectionName())->transaction(function () use ($agent, $country, $start, $end, $perStop, $perFailed): ?DeliveryPayslip {
            $from = $start->shiftTimezone($country->timezone)->startOfDay()->utc();
            $until = $end->shiftTimezone($country->timezone)->endOfDay()->utc();
            $assignments = $this->unpaidAssignments()->where('delivery_agent_id', $agent->id)->lockForUpdate()->get()
                ->filter(fn (DeliveryAssignment $assignment): bool => $this->finishedAt($assignment)->betweenIncluded($from, $until))
                ->values();

            if ($assignments->isEmpty()) {
                return null;
            }

            $currency = $country->currency;
            $stop = $this->money->of((string) $perStop, $currency);
            $failedRate = $this->money->of((string) ($perFailed ?? '0'), $currency);
            $total = $this->money->zero($currency);
            $delivered = 0;
            $failed = 0;
            $lines = [];

            foreach ($assignments as $assignment) {
                $isDelivered = $assignment->status === AssignmentStatus::Delivered;
                $amount = $isDelivered ? $stop : $failedRate;
                $isDelivered ? $delivered++ : $failed++;
                $total = $total->plus($amount);
                $lines[] = ['delivery_assignment_id' => $assignment->id, 'outcome' => $isDelivered ? 'delivered' : 'failed', 'finished_at' => $this->finishedAt($assignment), 'amount' => $this->money->toStorage($amount)];
            }

            $payslip = DeliveryPayslip::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'delivery_agent_id' => $agent->id,
                'carrier_id' => $agent->carrier_id,
                'currency' => $currency,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'delivered' => $delivered,
                'failed' => $failed,
                'per_stop' => $this->money->toStorage($stop),
                'per_failed' => $perFailed === null ? null : $this->money->toStorage($failedRate),
                'total' => $this->money->toStorage($total),
                'issued_at' => now(),
            ]);
            $payslip->forceFill(['number' => sprintf('PS-%s-%06d', $end->format('Ym'), $payslip->id)])->save();

            foreach ($lines as $line) {
                DeliveryPayslipLine::query()->create($line + ['delivery_payslip_id' => $payslip->id]);
            }

            if ($total->isPositive()) {
                $this->ledger->post("payslip:{$payslip->id}", 'agent_payslip', (new Posting)
                    ->debit(AccountRef::platform(LedgerAccountCode::DeliveryPayExpense), $total)
                    ->credit(AccountRef::agentWallet($agent->user_id), $total), reference: $payslip, description: "Payslip {$payslip->number}");
            }

            return $payslip;
        });
    }

    /**
     * @return array{available: Money, reserved: Money, paid_out: Money}
     */
    public function balances(DeliveryAgent $agent, string $currency): array
    {
        $paidOut = DeliveryWithdrawal::query()->where('delivery_agent_id', $agent->id)->where('status', AgentWithdrawalStatus::Paid)->where('currency', $currency)->sum('amount');

        return [
            'available' => $this->ledger->balance(AccountRef::agentWallet($agent->user_id), $currency),
            'reserved' => $this->ledger->balance(AccountRef::agentWallet($agent->user_id, WalletBucket::Reserved), $currency),
            'paid_out' => $this->money->of((string) $paidOut, $currency),
        ];
    }

    public function minimum(string $currency): Money
    {
        return $this->money->of((string) ($this->settings->get('delivery.withdrawal_minimum') ?? '0'), $currency);
    }

    /**
     * @param  array{account_holder: string, bank_name: string, iban: string}  $payout
     */
    public function requestWithdrawal(DeliveryAgent $agent, Money $amount, array $payout, ?int $userId = null): DeliveryWithdrawal
    {
        $currency = $amount->getCurrency()->getCurrencyCode();

        return DB::connection($agent->getConnectionName())->transaction(function () use ($agent, $amount, $payout, $userId, $currency): DeliveryWithdrawal {
            DeliveryAgent::query()->lockForUpdate()->findOrFail($agent->id);

            if (! $amount->isPositive() || $amount->isLessThan($this->minimum($currency))) {
                throw new TransitionRefused(__('The amount is below the minimum withdrawal of :amount.', ['amount' => $this->minimum($currency)->getAmount().' '.$currency]));
            }

            if (DeliveryWithdrawal::query()->where('delivery_agent_id', $agent->id)->whereIn('status', [AgentWithdrawalStatus::Pending, AgentWithdrawalStatus::Approved])->exists()) {
                throw new TransitionRefused(__('You already have a withdrawal in progress.'));
            }

            if ($amount->isGreaterThan($this->balances($agent, $currency)['available'])) {
                throw new TransitionRefused(__('The amount is more than your available balance.'));
            }

            $withdrawal = DeliveryWithdrawal::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'delivery_agent_id' => $agent->id,
                'carrier_id' => $agent->carrier_id,
                'currency' => $currency,
                'amount' => $this->money->toStorage($amount),
                'status' => AgentWithdrawalStatus::Pending,
                'payout_details' => [
                    'account_holder' => trim($payout['account_holder']),
                    'bank_name' => trim($payout['bank_name']),
                    'iban' => self::normaliseIban($payout['iban']),
                ],
                'requested_by_user_id' => $userId,
            ]);
            $withdrawal->forceFill(['number' => sprintf('AW-%06d', $withdrawal->id)])->save();
            $this->ledger->post("agent-withdrawal:{$withdrawal->id}:request", 'agent_withdrawal', (new Posting)
                ->debit(AccountRef::agentWallet($agent->user_id), $amount)
                ->credit(AccountRef::agentWallet($agent->user_id, WalletBucket::Reserved), $amount), ActorType::Agent, $userId, $withdrawal, "Withdrawal {$withdrawal->number}");
            $this->machine->created($withdrawal, ActorType::Agent, $userId);

            return $withdrawal;
        });
    }

    public function cancel(DeliveryWithdrawal $withdrawal, int $userId): DeliveryWithdrawal
    {
        if ($withdrawal->status !== AgentWithdrawalStatus::Pending) {
            throw new TransitionRefused(__('Only a pending withdrawal can be cancelled.'));
        }

        return $this->release($withdrawal, AgentWithdrawalStatus::Cancelled, ActorType::Agent, $userId, 'Cancelled by the agent');
    }

    public function approve(DeliveryWithdrawal $withdrawal, int $reviewerUserId, ActorType $actor = ActorType::Admin): DeliveryWithdrawal
    {
        return $this->machine->move($withdrawal, AgentWithdrawalStatus::Approved, $actor, $reviewerUserId, null, [
            'approved_by_user_id' => $reviewerUserId,
            'approved_at' => now(),
        ]);
    }

    public function reject(DeliveryWithdrawal $withdrawal, int $reviewerUserId, string $reason, ActorType $actor = ActorType::Admin): DeliveryWithdrawal
    {
        if (trim($reason) === '') {
            throw new TransitionRefused(__('Give a reason for the rejection.'));
        }

        return $this->release($withdrawal, AgentWithdrawalStatus::Rejected, $actor, $reviewerUserId, $reason);
    }

    /**
     * Bank transfer sent: Dr agent reserved / Cr bank clearing (fleet) or
     * carrier receivable (the carrier paid its driver).
     */
    public function markPaid(DeliveryWithdrawal $withdrawal, int $reviewerUserId, string $reference, ActorType $actor = ActorType::Admin): DeliveryWithdrawal
    {
        if (trim($reference) === '') {
            throw new TransitionRefused(__('Enter the bank transfer reference.'));
        }

        if ((bool) $this->settings->get('delivery.withdrawal_four_eyes') && $withdrawal->approved_by_user_id === $reviewerUserId) {
            throw new TransitionRefused(__('Another reviewer must mark a withdrawal you approved as paid.'));
        }

        return DB::connection($withdrawal->getConnectionName())->transaction(function () use ($withdrawal, $reviewerUserId, $reference, $actor): DeliveryWithdrawal {
            $this->machine->move($withdrawal, AgentWithdrawalStatus::Paid, $actor, $reviewerUserId, null, [
                'paid_by_user_id' => $reviewerUserId,
                'paid_at' => now(),
                'reference' => mb_substr(trim($reference), 0, 100),
            ]);
            $amount = $this->money->of((string) $withdrawal->amount, $withdrawal->currency);
            $agentUserId = (int) DeliveryAgent::query()->whereKey($withdrawal->delivery_agent_id)->value('user_id');
            $this->ledger->post("agent-withdrawal:{$withdrawal->id}:paid", 'agent_withdrawal_paid', (new Posting)
                ->debit(AccountRef::agentWallet($agentUserId, WalletBucket::Reserved), $amount)
                ->credit($withdrawal->carrier_id === null ? AccountRef::platform(LedgerAccountCode::BankClearing) : AccountRef::carrierReceivable($withdrawal->carrier_id), $amount), $actor, $reviewerUserId, $withdrawal, "Withdrawal {$withdrawal->number} paid");

            return $withdrawal;
        });
    }

    /**
     * The agent's wallet movements, newest first.
     *
     * @return Builder<LedgerEntry>
     */
    public function history(DeliveryAgent $agent): Builder
    {
        return LedgerEntry::query()
            ->whereHas('account', fn ($query) => $query->where('code', LedgerAccountCode::AgentPayable)->where('owner_type', 'agent')->where('owner_id', $agent->user_id))
            ->with(['account', 'transaction'])
            ->latest('id');
    }

    public static function normaliseIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    /**
     * ISO 13616 IBAN check (country letters, length, mod-97).
     */
    public static function validIban(string $iban): bool
    {
        $iban = self::normaliseIban($iban);

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $digits = '';

        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $character) {
            $digits .= ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder === 1;
    }

    private function release(DeliveryWithdrawal $withdrawal, AgentWithdrawalStatus $to, ActorType $actor, int $userId, string $reason): DeliveryWithdrawal
    {
        return DB::connection($withdrawal->getConnectionName())->transaction(function () use ($withdrawal, $to, $actor, $userId, $reason): DeliveryWithdrawal {
            $this->machine->move($withdrawal, $to, $actor, $userId, $reason, ['reason' => mb_substr($reason, 0, 255)]);
            $amount = $this->money->of((string) $withdrawal->amount, $withdrawal->currency);
            $agentUserId = (int) DeliveryAgent::query()->whereKey($withdrawal->delivery_agent_id)->value('user_id');
            $this->ledger->post("agent-withdrawal:{$withdrawal->id}:{$to->value}", 'agent_withdrawal_released', (new Posting)
                ->debit(AccountRef::agentWallet($agentUserId, WalletBucket::Reserved), $amount)
                ->credit(AccountRef::agentWallet($agentUserId), $amount), $actor, $userId, $withdrawal);

            return $withdrawal;
        });
    }

    /**
     * Finished assignments not on a payslip.
     *
     * @return Builder<DeliveryAssignment>
     */
    private function unpaidAssignments(): Builder
    {
        return DeliveryAssignment::query()
            ->whereIn('status', [AssignmentStatus::Delivered, AssignmentStatus::Failed])
            ->whereNotIn('id', DeliveryPayslipLine::query()->select('delivery_assignment_id'));
    }

    private function finishedAt(DeliveryAssignment $assignment): CarbonImmutable
    {
        return CarbonImmutable::parse($assignment->delivered_at ?? $assignment->failed_at ?? $assignment->updated_at)->utc();
    }

    /**
     * The period (local dates) holding the agent's oldest unpaid finished assignment.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function oldestUnpaidPeriod(DeliveryAgent $agent, Country $country): ?array
    {
        $oldest = $this->unpaidAssignments()->where('delivery_agent_id', $agent->id)->get()
            ->map(fn (DeliveryAssignment $assignment): CarbonImmutable => $this->finishedAt($assignment))
            ->sort()->first();

        return $oldest === null ? null : $this->periodOf($oldest->setTimezone($country->timezone));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} local start and end dates (midnight)
     */
    public function periodOf(CarbonImmutable $local): array
    {
        $day = CarbonImmutable::parse($local->toDateString());

        return $this->settings->get('delivery.payslip_period') === 'monthly'
            ? [$day->startOfMonth(), $day->endOfMonth()->startOfDay()]
            : [$day->startOfWeek(CarbonImmutable::MONDAY), $day->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)];
    }
}
