<?php

use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\PromotionalCreditStatus;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Exceptions\InsufficientFunds;
use App\Domains\Finance\Exceptions\UnbalancedPosting;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Models\LedgerTransaction;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Finance\Support\Wallets;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
    $this->ledger = app(Ledger::class);
    $this->wallets = app(Wallets::class);
});

describe('ledger', function () {
    it('refuses unbalanced, single-leg and mixed-currency postings and writes nothing', function (Posting $posting) {
        expect(fn () => $this->ledger->post('bad', 'test', $posting))->toThrow(UnbalancedPosting::class)
            ->and(LedgerTransaction::query()->count())->toBe(0);
    })->with([
        'unbalanced' => fn () => (new Posting)->debit(AccountRef::platform(LedgerAccountCode::BankClearing), Money::of('10.00', 'AED'))->credit(AccountRef::platform(LedgerAccountCode::OrderLiability), Money::of('9.99', 'AED')),
        'one leg' => fn () => (new Posting)->debit(AccountRef::platform(LedgerAccountCode::BankClearing), Money::of('10.00', 'AED')),
        'two currencies' => fn () => (new Posting)->debit(AccountRef::platform(LedgerAccountCode::BankClearing), Money::of('10.00', 'AED'))->credit(AccountRef::platform(LedgerAccountCode::OrderLiability), Money::of('10.000', 'OMR')),
    ]);

    it('is idempotent per key and keeps balances on the normal side', function () {
        $posting = (new Posting)->debit(AccountRef::platform(LedgerAccountCode::BankClearing), Money::of('25.50', 'AED'))
            ->credit(AccountRef::platform(LedgerAccountCode::OrderLiability), Money::of('25.50', 'AED'));

        $first = $this->ledger->post('key-1', 'test', $posting);
        $second = $this->ledger->post('key-1', 'test', $posting);

        expect($second->id)->toBe($first->id)
            ->and(LedgerTransaction::query()->count())->toBe(1)
            ->and($first->fresh()->total)->toBe('25.5000')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::BankClearing)))->toBe('25.50')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::OrderLiability)))->toBe('25.50');
        assertLedgerBalanced();
    });

    it('keeps entries and transactions immutable', function () {
        $this->ledger->post('key-2', 'test', (new Posting)->debit(AccountRef::platform(LedgerAccountCode::BankClearing), Money::of('1.00', 'AED'))
            ->credit(AccountRef::platform(LedgerAccountCode::OrderLiability), Money::of('1.00', 'AED')));

        expect(fn () => LedgerEntry::query()->first()->update(['amount' => '2']))->toThrow(LogicException::class)
            ->and(fn () => LedgerTransaction::query()->first()->delete())->toThrow(LogicException::class);
    });

    it('rounds 3-decimal currencies and records the balance after each entry', function () {
        $this->actAsCountry('kwt');
        $this->ledger->post('kwd-1', 'test', (new Posting)->debit(AccountRef::platform(LedgerAccountCode::BankClearing), Money::of('1.005', 'KWD'))
            ->credit(AccountRef::platform(LedgerAccountCode::OrderLiability), Money::of('1.005', 'KWD')));
        $this->ledger->post('kwd-2', 'test', (new Posting)->debit(AccountRef::platform(LedgerAccountCode::OrderLiability), Money::of('0.333', 'KWD'))
            ->credit(AccountRef::platform(LedgerAccountCode::BankClearing), Money::of('0.333', 'KWD')));

        expect(ledgerBalance(AccountRef::platform(LedgerAccountCode::BankClearing)))->toBe('0.672')
            ->and(LedgerEntry::query()->latest('id')->first()->balance_after)->toBe('0.6720');
        assertLedgerBalanced();
    });
});

describe('Nawy Credits', function () {
    it('never lets a wallet go below zero', function () {
        $customer = User::factory()->create();
        giveCredits($customer->id, '10.00');

        expect(fn () => $this->wallets->pay($customer->id, Money::of('10.01', 'AED'), 'pay-1'))->toThrow(InsufficientFunds::class)
            ->and(ledgerBalance(AccountRef::customerWallet($customer->id)))->toBe('10.00');
    });

    it('spends promotional credits first, soonest expiry first, then spendable', function () {
        $customer = User::factory()->create();
        giveCredits($customer->id, '50.00');
        $late = $this->wallets->grantPromotional($customer->id, Money::of('20.00', 'AED'), 'promo-late', 'campaign', now()->addDays(30));
        $soon = $this->wallets->grantPromotional($customer->id, Money::of('5.00', 'AED'), 'promo-soon', 'campaign', now()->addDays(2));

        $split = $this->wallets->pay($customer->id, Money::of('30.00', 'AED'), 'pay-2');

        expect((string) $split['promotional']->getAmount())->toBe('25.00')
            ->and((string) $split['spendable']->getAmount())->toBe('5.00')
            ->and($soon->fresh()->status)->toBe(PromotionalCreditStatus::Used)
            ->and($late->fresh()->status)->toBe(PromotionalCreditStatus::Used)
            ->and(ledgerBalance(AccountRef::customerWallet($customer->id)))->toBe('45.00')
            ->and(ledgerBalance(AccountRef::customerWallet($customer->id, WalletBucket::Promotional)))->toBe('0.00')
            ->and($this->wallets->pay($customer->id, Money::of('30.00', 'AED'), 'pay-2'))->toEqual($split);
        assertLedgerBalanced();
    });

    it('expires unused promotional credits and keeps the bucket equal to the grants', function () {
        $customer = User::factory()->create();
        $grant = $this->wallets->grantPromotional($customer->id, Money::of('15.00', 'AED'), 'promo-1', 'campaign', now()->addDay());
        $this->wallets->pay($customer->id, Money::of('4.00', 'AED'), 'pay-3');

        $this->travel(2)->days();
        $this->artisan('nawy:wallet:expire-promotional')->assertSuccessful();
        $this->artisan('nawy:wallet:expire-promotional')->assertSuccessful();

        expect($grant->fresh())->status->toBe(PromotionalCreditStatus::Expired)->remaining->toBe('0.0000')
            ->and(ledgerBalance(AccountRef::customerWallet($customer->id, WalletBucket::Promotional)))->toBe('0.00')
            ->and(LedgerTransaction::query()->where('type', 'promotional_expiry')->count())->toBe(1);
        assertLedgerBalanced();
    });

    it('freezes and unfreezes spendable credits', function () {
        $customer = User::factory()->create();
        giveCredits($customer->id, '12.00');

        $this->wallets->freeze($customer->id, 'AED', 1);
        expect((string) $this->wallets->available($customer->id, 'AED')->getAmount())->toBe('0.00')
            ->and(ledgerBalance(AccountRef::customerWallet($customer->id, WalletBucket::Frozen)))->toBe('12.00');

        $this->wallets->freeze($customer->id, 'AED', 1, false);
        expect((string) $this->wallets->available($customer->id, 'AED')->getAmount())->toBe('12.00');
    });

    it('keeps credits per country', function () {
        $customer = User::factory()->create();
        giveCredits($customer->id, '40.00');
        $this->actAsCountry('omn');

        expect((string) $this->wallets->available($customer->id, 'OMR')->getAmount())->toBe('0.000');
    });

    it('shows balances and movements through the API', function () {
        [$customer, $token] = customerWithToken();
        giveCredits($customer->id, '30.00');
        $this->wallets->grantPromotional($customer->id, Money::of('5.00', 'AED'), 'promo-api', 'campaign', now()->addDays(10));

        $this->withToken($token)->getJson('/api/v1/uae-en/wallet')->assertOk()
            ->assertJsonPath('data.available', ['amount' => '35.00', 'currency' => 'AED'])
            ->assertJsonPath('data.promotional_expiring.0.amount.amount', '5.00');
        $this->withToken($token)->getJson('/api/v1/uae-en/wallet/transactions')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.direction', 'in');
        app('auth')->forgetGuards();
        $this->withoutToken()->getJson('/api/v1/uae-en/wallet')->assertUnauthorized();
    });
});

describe('paying with Nawy Credits', function () {
    it('offers credits only to a customer whose balance covers the amount', function () {
        [$customer, $token] = customerWithToken();
        giveCredits($customer->id, '100.00');

        $codes = fn (string $amount) => array_column($this->withToken($token)->getJson("/api/v1/uae-en/checkout/payment-methods?amount={$amount}")->json('data'), 'code');

        expect($codes('100.00'))->toContain('wallet')
            ->and($codes('100.01'))->not->toContain('wallet');
        app('auth')->forgetGuards();
        expect(array_column($this->withoutToken()->getJson('/api/v1/uae-en/checkout/payment-methods?amount=50.00')->json('data'), 'code'))->not->toContain('wallet');
    });

    it('captures a wallet order at once and books it in the ledger', function () {
        $customer = User::factory()->create(['email_verified_at' => now()]);
        giveCredits($customer->id, '200.00');

        [$order] = placeOrderVia('wallet', '100.00', customer: $customer);
        $payment = $order->payments()->first();

        expect($payment->status)->toBe(PaymentStatus::Captured)
            ->and($payment->method)->toBe(PaymentMethod::Wallet)
            ->and($order->fresh()->payment_status)->toBe('captured')
            ->and(ledgerBalance(AccountRef::customerWallet($customer->id)))->toBe((string) Money::of('200.00', 'AED')->minus(Money::of((string) $order->grand_total, 'AED'))->getAmount())
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::WalletClearing)))->toBe('0.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::OrderLiability)))->toBe((string) Money::of((string) $order->grand_total, 'AED')->getAmount());
        assertLedgerBalanced();
    });

    it('refuses a wallet order the balance cannot cover', function () {
        $customer = User::factory()->create(['email_verified_at' => now()]);
        giveCredits($customer->id, '5.00');
        $token = $customer->createToken('t')->plainTextToken;
        $listing = sellableListing('100.00');
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);

        $this->withToken($token)->postJson('/api/v1/uae-en/orders', [
            'payment_method' => 'wallet',
            'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'payment_method_unavailable');
    });
});
