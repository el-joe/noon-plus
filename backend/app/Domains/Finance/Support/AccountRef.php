<?php

namespace App\Domains\Finance\Support;

use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\LedgerAccount;
use App\Domains\Payments\Enums\PaymentMethod;

/**
 * Names a ledger account: code + owner + bucket (currency comes from the
 * posting). Platform accounts have owner "platform"/0 and bucket "main".
 */
final readonly class AccountRef
{
    public function __construct(
        public LedgerAccountCode $code,
        public string $ownerType = LedgerAccount::OWNER_PLATFORM,
        public int $ownerId = 0,
        public string $bucket = 'main',
    ) {}

    public static function platform(LedgerAccountCode $code): self
    {
        return new self($code);
    }

    public static function customerWallet(int $customerUserId, WalletBucket $bucket = WalletBucket::Spendable): self
    {
        return new self(LedgerAccountCode::CustomerWallet, LedgerAccount::OWNER_CUSTOMER, $customerUserId, $bucket->value);
    }

    public static function vendor(int $vendorId, WalletBucket $bucket = WalletBucket::Spendable): self
    {
        return new self(LedgerAccountCode::VendorPayable, LedgerAccount::OWNER_VENDOR, $vendorId, $bucket->value);
    }

    /**
     * A marketer's commission wallet (WP5): pending (approved, clearing),
     * spendable, reserved (withdrawal requested) and clawback (negative =
     * owed back after a reversal of money already paid out).
     */
    public static function marketer(int $marketerProfileId, WalletBucket $bucket = WalletBucket::Spendable): self
    {
        return new self(LedgerAccountCode::MarketerPayable, LedgerAccount::OWNER_MARKETER, $marketerProfileId, $bucket->value);
    }

    /**
     * Money held by a payment provider until it settles to the bank, per gateway.
     */
    public static function gateway(PaymentMethod $method): self
    {
        return new self(LedgerAccountCode::GatewayClearing, LedgerAccount::OWNER_GATEWAY, 0, $method->value);
    }

    /**
     * Cash collected by a delivery agent (0 = not yet assigned, WP7).
     */
    public static function codReceivable(?int $agentUserId): self
    {
        return new self(LedgerAccountCode::CodReceivable, LedgerAccount::OWNER_AGENT, $agentUserId ?? 0);
    }

    /**
     * A delivery agent's pay wallet (issued payslips): spendable, and
     * reserved while a withdrawal is pending or approved. Owner = agent user.
     */
    public static function agentWallet(int $agentUserId, WalletBucket $bucket = WalletBucket::Spendable): self
    {
        return new self(LedgerAccountCode::AgentPayable, LedgerAccount::OWNER_AGENT, $agentUserId, $bucket->value);
    }

    /**
     * Money a carrier owes Nawy for an approved claim (WP7, lifecycle catalogue §6.4).
     */
    public static function carrierReceivable(int $carrierId): self
    {
        return new self(LedgerAccountCode::CarrierReceivable, LedgerAccount::OWNER_CARRIER, $carrierId);
    }

    /**
     * Acquisition commissions owed to an admin who brought vendors in (FR-FIN-6). Owner = central user id.
     */
    public static function staff(int $userId): self
    {
        return new self(LedgerAccountCode::StaffPayable, LedgerAccount::OWNER_STAFF, $userId);
    }

    public function key(): string
    {
        return implode(':', [$this->code->value, $this->ownerType, $this->ownerId, $this->bucket]);
    }
}
