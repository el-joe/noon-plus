<?php

namespace App\Domains\Finance\Enums;

/**
 * Chart of accounts (ADR 4, lifecycle catalogue §0 indicative accounts). One
 * row per code × owner × bucket × currency in `ledger_accounts`; assets and
 * expenses are debit-normal, liabilities and revenue credit-normal.
 */
enum LedgerAccountCode: string
{
    // Assets
    case GatewayClearing = 'gateway_clearing';
    case WalletClearing = 'wallet_clearing';
    case CodReceivable = 'cod_receivable';
    case BankClearing = 'bank_clearing';
    case CarrierReceivable = 'carrier_receivable';

    // Liabilities
    case OrderLiability = 'order_liability';
    case CustomerWallet = 'customer_wallet';
    case VendorPayable = 'vendor_payable';
    case MarketerPayable = 'marketer_payable';
    case GiftCardLiability = 'gift_card_liability';
    case VatPayable = 'vat_payable';

    // Revenue
    case CommissionRevenue = 'commission_revenue';
    case ShippingRevenue = 'shipping_revenue';
    case WarrantyRevenue = 'warranty_revenue';
    case CodFeeRevenue = 'cod_fee_revenue';
    case FeeRevenue = 'fee_revenue';
    case MembershipRevenue = 'membership_revenue';
    case BreakageRevenue = 'breakage_revenue';
    case AdRevenue = 'ad_revenue';

    // Expenses
    case PromoExpense = 'promo_expense';
    case ShippingSubsidyExpense = 'shipping_subsidy_expense';
    case WarrantyClaimsExpense = 'warranty_claims_expense';
    case GoodwillExpense = 'goodwill_expense';

    public function normalSide(): string
    {
        return match ($this) {
            self::GatewayClearing, self::WalletClearing, self::CodReceivable, self::BankClearing, self::CarrierReceivable,
            self::PromoExpense, self::ShippingSubsidyExpense, self::WarrantyClaimsExpense, self::GoodwillExpense => 'debit',
            default => 'credit',
        };
    }

    /**
     * Balances that must never go below zero (customer money and gift-card
     * value). Vendor payables may go negative after a clawback.
     */
    public function mustStayNonNegative(): bool
    {
        return in_array($this, [self::CustomerWallet, self::GiftCardLiability], true);
    }
}
