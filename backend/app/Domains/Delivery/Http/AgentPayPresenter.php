<?php

namespace App\Domains\Delivery\Http;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Delivery\Models\DeliveryPayslip;
use App\Domains\Delivery\Models\DeliveryPayslipLine;
use App\Domains\Delivery\Models\DeliveryWithdrawal;
use App\Domains\Documents\Http\DocumentPresenter;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;

/**
 * Delivery app shapes for agent documents, payslips and withdrawals
 * (docs/project/storefront-api-additions.md § "Delivery agent documents,
 * payslips and wallet").
 */
class AgentPayPresenter
{
    /**
     * Days before `valid_until` from which a document counts as expiring soon.
     */
    public const EXPIRING_SOON_DAYS = DocumentPresenter::EXPIRING_SOON_DAYS;

    /**
     * @return array<string, mixed>
     */
    public static function requirement(DocumentType $type): array
    {
        return DocumentPresenter::requirement($type);
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(Document $document): array
    {
        return DocumentPresenter::document($document);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payslip(DeliveryPayslip $payslip, bool $withLines = false): array
    {
        $money = fn (mixed $amount): ?array => $amount === null ? null : MoneyResource::of((string) $amount, $payslip->currency);

        return [
            'number' => $payslip->number,
            'period_start' => $payslip->period_start->toDateString(),
            'period_end' => $payslip->period_end->toDateString(),
            'delivered' => $payslip->delivered,
            'failed' => $payslip->failed,
            'per_stop' => $money($payslip->per_stop),
            'per_failed' => $money($payslip->per_failed),
            'total' => $money($payslip->total),
            'issued_at' => $payslip->issued_at->toIso8601String(),
        ] + ($withLines ? ['lines' => $payslip->lines->map(fn (DeliveryPayslipLine $line): array => [
            'assignment_number' => $line->assignment?->number,
            'sub_order_number' => $line->assignment?->subOrder?->number,
            'outcome' => $line->outcome,
            'finished_at' => $line->finished_at->toIso8601String(),
            'amount' => $money($line->amount),
        ])->all()] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function withdrawal(DeliveryWithdrawal $withdrawal): array
    {
        return [
            'number' => $withdrawal->number,
            'amount' => MoneyResource::of((string) $withdrawal->amount, $withdrawal->currency),
            'status' => $withdrawal->status->value,
            'reason' => $withdrawal->reason,
            'payout' => $withdrawal->maskedPayout(),
            'requested_at' => $withdrawal->created_at?->toIso8601String(),
            'approved_at' => $withdrawal->approved_at?->toIso8601String(),
            'paid_at' => $withdrawal->paid_at?->toIso8601String(),
            'reference' => $withdrawal->reference,
        ];
    }
}
