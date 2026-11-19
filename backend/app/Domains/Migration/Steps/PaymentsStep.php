<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Payments\Enums\PaymentMethod;
use Illuminate\Support\Collection;

/**
 * Payments (MG4, rule R2 via the order; plan §6). Legacy gateway
 * transactions become new `payments` with their gateway references; the
 * legacy row id is kept as the payment `reference`. An authorisation and its
 * capture / void are one new payment (the capture or void is linked to it);
 * gateway `refund` transactions become `payment_refunds` of the order's one
 * captured payment. Business refunds (`refunds`) become `refunds` of the
 * order. Amounts are copied, never converted: a row whose currency is not
 * its country's, whose order did not migrate, whose gateway has no new
 * method, or a chargeback (no new equivalent) is quarantined with a reason.
 * Raw gateway payloads, FX details and refund deductions stay readable in
 * `legacy_records` next to the mapped row.
 */
class PaymentsStep extends Step
{
    /**
     * Legacy refund-transaction / payment statuses → new payment refund status.
     *
     * @var array<string, string>
     */
    public const REFUND_TRANSACTION_STATUSES = ['succeeded' => 'completed', 'pending' => 'pending', 'failed' => 'failed', 'cancelled' => 'failed'];

    public function name(): string
    {
        return 'payments';
    }

    protected function order(): array
    {
        return ['payment_transactions', 'refunds'];
    }

    protected function migratePaymentTransactions(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];

            if ($row['type'] === 'chargeback') {
                $this->quarantine->put($table, $id, 'shape_unsupported', 'chargebacks have no new equivalent (handled as disputes)', null, $row);

                continue;
            }

            if ($row['type'] === 'refund') {
                $this->paymentRefund($table, $row);

                continue;
            }

            if (in_array($row['type'], ['capture', 'void'], true)) {
                $authorization = $this->authorizationOf($row);

                if ($authorization !== null) {
                    $this->foldIntoAuthorization($table, $row, $authorization);

                    continue;
                }

                if ($row['type'] === 'void') {
                    $this->quarantine->put($table, $id, 'parent_missing', 'void without an authorisation on the same order', null, $row);

                    continue;
                }
            }

            $this->payment($table, $row);
        }
    }

    protected function migrateRefunds(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            if (strtoupper((string) $row['currency']) !== $this->countries->currencyOf($code)) {
                $this->quarantine->put($table, $id, 'currency_mismatch', "refund in {$row['currency']}", $code, $row);

                continue;
            }

            $orderId = $this->ids->find('orders', (string) $row['order_id'], $code, 'orders');

            if ($orderId === null) {
                $this->quarantine->put($table, $id, $this->quarantine->has('orders', (string) $row['order_id']) ? 'parent_quarantined' : 'parent_unmigrated', 'order not migrated', $code, $row);

                continue;
            }

            $subOrderId = $row['sub_order_id'] === null ? null : $this->ids->find('sub_orders', (string) $row['sub_order_id'], $code, 'sub_orders');

            if ($row['sub_order_id'] !== null && $subOrderId === null) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', 'sub-order not migrated', $code, $row);

                continue;
            }

            $paymentRefundId = $row['refund_transaction_id'] === null ? null : $this->ids->find('payment_transactions', (string) $row['refund_transaction_id'], $code, 'payment_refunds');
            $refundTransaction = $row['refund_transaction_id'] === null ? null : $this->source->find('payment_transactions', (string) $row['refund_transaction_id']);
            $amount = $row['net_refund'] ?? $row['amount'];
            $deductions = bcadd(self::decimal($row['gateway_fee_deducted']), self::decimal($row['tax_deducted']), 4);

            $this->writer->upsert($table, $id, $code, 'refunds', [
                'number' => 'RF-L'.strtoupper(str_replace('-', '', $id)),
                'order_id' => $orderId,
                'sub_order_id' => $subOrderId,
                'customer_user_id' => (int) MigrationContext::db($code)->table('orders')->where('id', $orderId)->value('customer_user_id'),
                'source_type' => 'admin',
                'source_id' => null,
                'currency' => strtoupper((string) $row['currency']),
                'amount' => $amount,
                'destination' => strtolower((string) ($refundTransaction['gateway'] ?? '')) === 'wallet' ? 'wallet' : 'original',
                'status' => $row['status'],
                'idempotency_key' => 'legacy:'.$id,
                'payment_refund_id' => $paymentRefundId,
                'reason' => mb_substr(trim('Legacy '.$row['refund_type'].' refund ('.$row['reason'].')'.($row['reason_notes'] ? ': '.$row['reason_notes'] : '')), 0, 255),
                'failure_message' => null,
                'processed_at' => $row['status'] === 'completed' ? ($row['updated_at'] ?? $row['created_at']) : null,
                ...$this->timestamps($row),
            ]);

            if (bccomp($deductions, '0', 4) !== 0 || $row['vendor_charged_back']) {
                $this->keepLegacyRow($table, $id, $row, $code);
            }

            $this->context->sum($table, $row['currency'], (string) $amount);
        }
    }

    /**
     * A sale, an authorisation or a capture without one: one new payment.
     *
     * @param  array<string, mixed>  $row
     */
    private function payment(string $table, array $row): ?int
    {
        $id = (string) $row['id'];
        $code = $this->singleCountry($table, $row);

        if ($code === null) {
            return null;
        }

        $plan = $this->paymentPlan($row, $code);

        if (is_string($plan)) {
            $this->quarantine->put($table, $id, strtok($plan, ':') ?: 'unresolved', $plan, $code, $row);

            return null;
        }

        $paymentId = $this->writer->upsert($table, $id, $code, 'payments', $plan);

        if ($row['raw_response'] !== null || $row['gateway_currency'] !== null || $row['proof_file_path'] !== null || bccomp(self::decimal($row['gateway_fee']), '0', 4) !== 0) {
            $this->keepLegacyRow($table, $id, $row, $code);
        }

        $this->context->sum($table, $row['currency'], (string) $row['amount']);

        return $paymentId;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|string
     */
    private function paymentPlan(array $row, string $code): array|string
    {
        $currency = strtoupper((string) $row['currency']);

        if ($currency !== $this->countries->currencyOf($code)) {
            return "currency_mismatch: payment in {$currency}, {$code} sells in ".$this->countries->currencyOf($code);
        }

        $orderId = $this->ids->find('orders', (string) $row['order_id'], $code, 'orders');

        if ($orderId === null) {
            return ($this->quarantine->has('orders', (string) $row['order_id']) ? 'parent_quarantined' : 'parent_unmigrated').': order not migrated';
        }

        $gateway = strtolower((string) $row['gateway']);
        $method = OrdersStep::GATEWAYS[$gateway] ?? match ($gateway) {
            'cod', 'cash_on_delivery' => PaymentMethod::Cod->value,
            'wallet' => PaymentMethod::Wallet->value,
            default => null,
        };

        if ($method === null) {
            return "method_unmapped: gateway {$row['gateway']} has no new equivalent";
        }

        $customerUserId = $this->userOf('customers', (string) $row['customer_id']);

        if ($customerUserId === null) {
            return 'owner_unresolved: customer not migrated';
        }

        $amount = (string) $row['amount'];
        $status = match ($row['status']) {
            'pending' => 'pending',
            'failed', 'cancelled' => 'failed',
            default => $row['type'] === 'authorization' ? 'authorized' : 'captured',
        };
        $capturedAmount = $status === 'captured' ? $amount : '0';
        $capturedAt = $status === 'captured' ? ($row['processed_at'] ?? $row['created_at']) : null;
        $connection = $this->source->connection();

        if ($row['type'] === 'authorization' && $status === 'authorized') {
            $follow = $connection->table('payment_transactions')->where('order_id', $row['order_id'])->whereIn('type', ['capture', 'void'])->where('status', 'succeeded')->orderBy('created_at')->get();
            $captures = $follow->where('type', 'capture');

            if ($captures->isNotEmpty()) {
                $status = 'captured';
                $capturedAmount = $captures->reduce(fn (string $carry, object $capture): string => bcadd($carry, (string) $capture->amount, 4), '0');
                $capturedAt = $captures->last()->processed_at ?? $captures->last()->created_at;
            } elseif ($follow->where('type', 'void')->isNotEmpty()) {
                $status = 'voided';
            }
        }

        $refunded = '0';

        if ($status === 'captured' && $this->capturedPaymentsOf((string) $row['order_id'])->count() === 1) {
            foreach ($connection->table('payment_transactions')->where('order_id', $row['order_id'])->where('type', 'refund')->where('status', 'succeeded')->pluck('amount') as $refundAmount) {
                $refunded = bcadd($refunded, (string) $refundAmount, 4);
            }

            if (bccomp($refunded, '0', 4) > 0) {
                $status = bccomp($refunded, $capturedAmount, 4) >= 0 ? 'refunded' : 'partially_refunded';
            }
        }

        return [
            'reference' => (string) $row['id'],
            'order_id' => $orderId,
            'payable_type' => null,
            'payable_id' => null,
            'customer_user_id' => $customerUserId,
            'method' => $method,
            'mode' => null,
            'status' => $status,
            'currency' => $currency,
            'amount' => $amount,
            'captured_amount' => $capturedAmount,
            'refunded_amount' => $refunded,
            'idempotency_key' => 'legacy:'.$row['id'],
            'gateway_reference' => mb_substr((string) $row['gateway_transaction_id'], 0, 191),
            'failure_code' => $row['status'] === 'cancelled' ? ($row['failure_code'] ?? 'cancelled') : $row['failure_code'],
            'failure_message' => $row['failure_message'] === null ? null : mb_substr((string) $row['failure_message'], 0, 255),
            'authorized_at' => $row['type'] === 'authorization' && $row['status'] === 'succeeded' ? ($row['processed_at'] ?? $row['created_at']) : null,
            'captured_at' => $capturedAt,
            ...$this->timestamps($row),
        ];
    }

    /**
     * A capture or void of an authorisation lives on as that payment.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $authorization
     */
    private function foldIntoAuthorization(string $table, array $row, array $authorization): void
    {
        $code = $this->singleCountry($table, $row);

        if ($code === null) {
            return;
        }

        $paymentId = $this->ids->find($table, (string) $authorization['id'], $code, 'payments') ?? $this->payment($table, $authorization);

        if ($paymentId === null) {
            $this->quarantine->put($table, (string) $row['id'], 'parent_quarantined', "authorisation {$authorization['id']} is quarantined", $code, $row);

            return;
        }

        $this->writer->link($table, (string) $row['id'], $code, 'payments', $paymentId, 'folded');
    }

    /**
     * A gateway refund of the order's one captured payment.
     *
     * @param  array<string, mixed>  $row
     */
    private function paymentRefund(string $table, array $row): void
    {
        $id = (string) $row['id'];
        $code = $this->singleCountry($table, $row);

        if ($code === null) {
            return;
        }

        $captured = $this->capturedPaymentsOf((string) $row['order_id']);

        if ($captured->count() !== 1) {
            $this->quarantine->put($table, $id, 'payment_ambiguous', $captured->count().' captured payment(s) on the order; the refund cannot be attached to one', $code, $row);

            return;
        }

        $parent = (array) $captured->first();
        $paymentId = $this->ids->find($table, (string) $parent['id'], $code, 'payments') ?? $this->payment($table, $parent);

        if ($paymentId === null) {
            $this->quarantine->put($table, $id, 'parent_quarantined', "payment {$parent['id']} is quarantined", $code, $row);

            return;
        }

        if (strtoupper((string) $row['currency']) !== strtoupper((string) $parent['currency'])) {
            $this->quarantine->put($table, $id, 'currency_mismatch', "refund in {$row['currency']}, payment in {$parent['currency']}", $code, $row);

            return;
        }

        $status = self::REFUND_TRANSACTION_STATUSES[$row['status']];

        $this->writer->upsert($table, $id, $code, 'payment_refunds', [
            'payment_id' => $paymentId,
            'idempotency_key' => 'legacy:'.$id,
            'amount' => $row['amount'],
            'status' => $status,
            'gateway_reference' => mb_substr((string) $row['gateway_transaction_id'], 0, 191),
            'reason' => $row['note'] === null ? null : mb_substr((string) $row['note'], 0, 255),
            'failure_message' => $row['failure_message'] === null ? null : mb_substr((string) $row['failure_message'], 0, 255),
            'actor_type' => 'system',
            'actor_user_id' => null,
            'completed_at' => $status === 'completed' ? ($row['processed_at'] ?? $row['created_at']) : null,
            ...$this->timestamps($row),
        ]);
        $this->context->sum($table, $row['currency'], (string) $row['amount']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function authorizationOf(array $row): ?array
    {
        $authorization = $this->source->connection()->table('payment_transactions')->where('order_id', $row['order_id'])->where('type', 'authorization')->orderBy('created_at')->first();

        return $authorization === null ? null : (array) $authorization;
    }

    /**
     * Succeeded sales, authorisations and stand-alone captures of an order.
     *
     * @return Collection<int, object>
     */
    private function capturedPaymentsOf(string $orderId): Collection
    {
        $rows = $this->source->connection()->table('payment_transactions')->where('order_id', $orderId)->where('status', 'succeeded')->whereIn('type', ['sale', 'authorization', 'capture'])->orderBy('created_at')->get();
        $hasAuthorization = $rows->contains('type', 'authorization');

        return $rows->reject(fn (object $transaction): bool => $transaction->type === 'capture' && $hasAuthorization)->values();
    }
}
