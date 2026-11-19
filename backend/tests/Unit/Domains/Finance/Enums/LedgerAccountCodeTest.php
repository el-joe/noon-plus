<?php

use App\Domains\Finance\Enums\LedgerAccountCode as Code;

it('increases assets and expenses with a debit and the rest with a credit', function (Code $account, string $side) {
    expect($account->normalSide())->toBe($side);
})->with([
    'gateway clearing (asset)' => [Code::GatewayClearing, 'debit'],
    'COD receivable (asset)' => [Code::CodReceivable, 'debit'],
    'carrier receivable (asset)' => [Code::CarrierReceivable, 'debit'],
    'promo expense' => [Code::PromoExpense, 'debit'],
    'goodwill expense' => [Code::GoodwillExpense, 'debit'],
    'customer wallet (liability)' => [Code::CustomerWallet, 'credit'],
    'vendor payable (liability)' => [Code::VendorPayable, 'credit'],
    'VAT payable (liability)' => [Code::VatPayable, 'credit'],
    'commission revenue' => [Code::CommissionRevenue, 'credit'],
    'ad revenue' => [Code::AdRevenue, 'credit'],
]);

it('keeps only customer wallets and gift card liability from going negative', function (Code $account) {
    expect($account->mustStayNonNegative())->toBe(in_array($account, [Code::CustomerWallet, Code::GiftCardLiability], true));
})->with(Code::cases());
