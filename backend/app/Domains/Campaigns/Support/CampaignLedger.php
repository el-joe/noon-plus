<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Shared\Money\MoneyFactory;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ledger postings of the marketer module (ADR 4) and the matching lines on
 * the store's next payout statement. A store is charged by debiting its
 * spendable payable (it may go negative until the next settlement) and
 * credited back the same way; platform campaigns are paid from the promo
 * expense account. Every posting has an idempotency key.
 */
class CampaignLedger
{
    public function __construct(private Ledger $ledger, private MoneyFactory $money) {}

    /**
     * Who pays a campaign's commissions: the store, or the platform.
     */
    public function payer(?int $vendorId): AccountRef
    {
        return $vendorId === null ? AccountRef::platform(LedgerAccountCode::PromoExpense) : AccountRef::vendor($vendorId, WalletBucket::Spendable);
    }

    /**
     * Charge a store (Dr vendor spendable / Cr `$credit`) and put a negative
     * line on its next statement.
     */
    public function chargeVendor(int $vendorId, Money $amount, string $itemType, Model $reference, string $key, string $description, AccountRef $credit): void
    {
        if (! $amount->isPositive()) {
            return;
        }

        $this->ledger->post($key, $itemType, (new Posting)->debit(AccountRef::vendor($vendorId), $amount)->credit($credit, $amount), reference: $reference, description: $description);
        $this->balanceItem($vendorId, $itemType, $reference, $description, $amount->negated());
    }

    /**
     * Give a store money back (Dr `$debit` / Cr vendor spendable) with a
     * positive statement line.
     */
    public function creditVendor(int $vendorId, Money $amount, string $itemType, Model $reference, string $key, string $description, AccountRef $debit): void
    {
        if (! $amount->isPositive()) {
            return;
        }

        $this->ledger->post($key, $itemType, (new Posting)->debit($debit, $amount)->credit(AccountRef::vendor($vendorId), $amount), reference: $reference, description: $description);
        $this->balanceItem($vendorId, $itemType, $reference, $description, $amount);
    }

    public function balanceItem(int $vendorId, string $itemType, Model $reference, string $description, Money $amount): void
    {
        VendorBalanceItem::query()->firstOrCreate(
            ['type' => $itemType, 'reference_type' => Str::snake(class_basename($reference)), 'reference_id' => $reference->getKey()],
            ['vendor_id' => $vendorId, 'description' => mb_substr($description, 0, 255), 'amount' => $this->money->toStorage($amount), 'is_eligible' => true],
        );
    }

    public function post(string $key, string $type, Posting $posting, ?Model $reference = null, ?string $description = null): void
    {
        if (count($posting->legs()) >= 2) {
            $this->ledger->post($key, $type, $posting, reference: $reference, description: $description);
        }
    }

    public function balance(AccountRef $ref, string $currency): Money
    {
        return $this->ledger->balance($ref, $currency);
    }
}
