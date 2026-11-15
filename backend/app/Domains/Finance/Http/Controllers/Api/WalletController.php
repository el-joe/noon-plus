<?php

namespace App\Domains\Finance\Http\Controllers\Api;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\PromotionalCreditStatus;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Models\PromotionalCredit;
use App\Domains\Finance\Support\Wallets;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nawy Credits for the customer (account credits page, app wallet 99:6129):
 * balances per bucket in this country and the movements.
 */
class WalletController extends Controller
{
    public function __construct(private Wallets $wallets, private CountryContext $countryContext) {}

    public function show(Request $request): JsonResponse
    {
        $currency = $this->countryContext->current()->currency;
        $balances = $this->wallets->balances($request->user()->id, $currency);
        $expiring = PromotionalCredit::query()
            ->where('customer_user_id', $request->user()->id)
            ->where('currency', $currency)
            ->whereIn('status', [PromotionalCreditStatus::Granted, PromotionalCreditStatus::PartiallyUsed])
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get();

        return ApiResponse::success([
            'currency' => $currency,
            'available' => MoneyResource::make((string) $balances['spendable']->plus($balances['promotional'])->getAmount()),
            'spendable' => MoneyResource::make((string) $balances['spendable']->getAmount()),
            'promotional' => MoneyResource::make((string) $balances['promotional']->getAmount()),
            'frozen' => MoneyResource::make((string) $balances['frozen']->getAmount()),
            'promotional_expiring' => $expiring->map(fn (PromotionalCredit $credit): array => [
                'amount' => MoneyResource::make((string) $credit->remaining),
                'expires_at' => $credit->expires_at->toIso8601String(),
                'source' => $credit->source,
            ])->all(),
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $entries = $this->wallets->history($request->user()->id, $this->countryContext->current()->currency)->paginate(30);

        return ApiResponse::success(collect($entries->items())->map(fn (LedgerEntry $entry): array => [
            'id' => $entry->id,
            'type' => $entry->transaction->type,
            'bucket' => $entry->account->bucket,
            'direction' => $entry->direction === 'credit' ? 'in' : 'out',
            'amount' => MoneyResource::make((string) $entry->amount),
            'balance_after' => MoneyResource::make((string) $entry->balance_after),
            'description' => $entry->transaction->description,
            'at' => $entry->created_at->toIso8601String(),
        ])->all(), ['total' => $entries->total(), 'page' => $entries->currentPage()]);
    }
}
