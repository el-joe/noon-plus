<?php

namespace App\Domains\Finance\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Http\Requests\LedgerEntriesRequest;
use App\Domains\Finance\Models\LedgerAccount;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Models\LedgerTransaction;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Localization\Label;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Finance → Ledger (read-only, ADR 4): the chart of accounts of the
 * current country with balances per currency, the journal entries (one
 * balanced transaction each) filtered by posting date, account and
 * reference type, an entry's debit / credit lines and a CSV export of the
 * filtered entries. Dates are in the admin time zone.
 */
class LedgerController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function accounts(Request $request): View
    {
        $filters = $request->validate([
            'code' => ['nullable', Rule::enum(LedgerAccountCode::class)],
            'owner_type' => ['nullable', 'string', 'max:16', 'regex:/^[a-z_]+$/'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ]);

        $accounts = LedgerAccount::query()
            ->when($filters['code'] ?? null, fn (Builder $query, string $code) => $query->where('code', $code))
            ->when($filters['owner_type'] ?? null, fn (Builder $query, string $owner) => $query->where('owner_type', $owner))
            ->when($filters['currency'] ?? null, fn (Builder $query, string $currency) => $query->where('currency', strtoupper($currency)))
            ->orderBy('code')->orderBy('owner_type')->orderBy('owner_id')->orderBy('currency')->orderBy('bucket')
            ->paginate(50)->withQueryString();

        return view('panels.admin.finance.ledger.accounts', [
            'country' => $this->countryContext->current(),
            'filters' => $filters,
            'accounts' => $accounts,
            'owners' => $this->ownerNames(collect($accounts->items())),
            // Balance per account code × currency across every owner and bucket (normal side of the code).
            'totals' => LedgerAccount::query()->selectRaw('code, currency, count(*) as accounts, sum(balance) as balance')
                ->groupBy('code', 'currency')->orderBy('code')->orderBy('currency')->get(),
            'ownerTypes' => LedgerAccount::query()->distinct()->orderBy('owner_type')->pluck('owner_type'),
            'currencies' => LedgerAccount::query()->distinct()->orderBy('currency')->pluck('currency'),
        ]);
    }

    public function entries(LedgerEntriesRequest $request): View
    {
        $filters = $request->validated();

        return view('panels.admin.finance.ledger.entries', [
            'country' => $this->countryContext->current(),
            'filters' => $filters,
            'transactions' => $this->filtered($filters)->withCount('entries')->latest('posted_at')->latest('id')->paginate(25)->withQueryString(),
            'account' => isset($filters['account']) ? LedgerAccount::query()->find($filters['account']) : null,
            'referenceTypes' => $this->referenceTypes(),
        ]);
    }

    public function show(int $transaction): View
    {
        $transaction = LedgerTransaction::query()->with('entries.account')->findOrFail($transaction);

        return view('panels.admin.finance.ledger.entry', [
            'country' => $this->countryContext->current(),
            'transaction' => $transaction,
            'owners' => $this->ownerNames($transaction->entries->pluck('account')),
        ]);
    }

    /**
     * One row per debit / credit line of every filtered entry (an entry's lines always balance).
     */
    public function export(LedgerEntriesRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $country = $this->countryContext->current();
        $timezone = config('nawy.admin_timezone');
        $query = LedgerEntry::query()->with('account')
            ->whereIn('ledger_transaction_id', $this->filtered($filters)->select('id'))
            ->with('transaction')
            ->orderBy('ledger_transaction_id')->orderBy('id');

        return response()->streamDownload(function () use ($query, $timezone): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['entry', 'posted_at', 'type', 'reference_type', 'reference_id', 'description', 'line', 'account_id', 'account_code', 'owner_type', 'owner_id', 'bucket', 'currency', 'debit', 'credit', 'balance_after']);

            $query->chunk(500, function ($lines) use ($out, $timezone): void {
                foreach ($lines as $line) {
                    $transaction = $line->transaction;
                    fputcsv($out, [
                        $transaction->id,
                        $transaction->posted_at->setTimezone($timezone)->format('Y-m-d H:i:s'),
                        $transaction->type,
                        $transaction->reference_type,
                        $transaction->reference_id,
                        $this->safeCell((string) $transaction->description),
                        $line->id,
                        $line->ledger_account_id,
                        $line->account->code->value,
                        $line->account->owner_type,
                        $line->account->owner_id,
                        $line->account->bucket,
                        $line->account->currency,
                        $line->direction === 'debit' ? $line->amount : '',
                        $line->direction === 'credit' ? $line->amount : '',
                        $line->balance_after,
                    ]);
                }
            });

            fclose($out);
        }, 'nawy-ledger-'.$country->code.'-'.now($timezone)->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<LedgerTransaction>
     */
    private function filtered(array $filters): Builder
    {
        $timezone = config('nawy.admin_timezone');

        return LedgerTransaction::query()
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('posted_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('posted_at', '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc()))
            ->when($filters['account'] ?? null, fn (Builder $query, int|string $account) => $query->whereHas('entries', fn (Builder $lines) => $lines->where('ledger_account_id', (int) $account)))
            ->when($filters['reference_type'] ?? null, fn (Builder $query, string $type) => $query->where('reference_type', $type));
    }

    /**
     * @return array<string, string>
     */
    private function referenceTypes(): array
    {
        return LedgerTransaction::query()->whereNotNull('reference_type')->distinct()->orderBy('reference_type')->pluck('reference_type')
            ->mapWithKeys(fn (string $type): array => [$type => Label::of($type)])->all();
    }

    /**
     * Readable owner names of accounts: the platform, a store name, or the owner kind and id.
     *
     * @param  Collection<int, LedgerAccount|null>  $accounts
     * @return array<int, string> account id → owner
     */
    private function ownerNames(Collection $accounts): array
    {
        $accounts = $accounts->filter();
        $stores = Vendor::query()->whereKey($accounts->where('owner_type', LedgerAccount::OWNER_VENDOR)->pluck('owner_id')->unique()->all())->pluck('store_name', 'id');

        return $accounts->mapWithKeys(fn (LedgerAccount $account): array => [$account->id => match (true) {
            $account->owner_type === LedgerAccount::OWNER_PLATFORM => __('Platform'),
            $account->owner_type === LedgerAccount::OWNER_VENDOR && $stores->has($account->owner_id) => $stores[$account->owner_id].' (V-'.$account->owner_id.')',
            default => Label::of($account->owner_type).' #'.$account->owner_id,
        }])->all();
    }

    /**
     * Keeps a spreadsheet from reading free text as a formula (CSV injection).
     */
    private function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
