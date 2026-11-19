<?php

namespace App\Domains\Migration\Steps;

/**
 * Delivery (MG8, rule R2 via sub-order): shipments and agent assignments of
 * migrated sub-orders keep their status, delivery code and COD amount.
 * Children of a quarantined order are quarantined with it.
 */
class DeliveryStep extends Step
{
    /**
     * @var array<string, string>
     */
    public const FAILURE_REASONS = ['customer_not_home' => 'not_home', 'wrong_address' => 'wrong_address', 'customer_refused' => 'refused'];

    public function name(): string
    {
        return 'delivery';
    }

    protected function order(): array
    {
        return ['shipments', 'delivery_assignments'];
    }

    protected function migrateShipments(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $target = $this->subOrder($table, $row);

            if ($target === null) {
                continue;
            }

            [$code, $subOrderId] = $target;
            $this->writer->upsert($table, (string) $row['id'], $code, 'shipments', [
                'number' => mb_substr((string) ($row['tracking_number'] ?: 'LEG-'.$row['id']), 0, 40),
                'sub_order_id' => $subOrderId,
                'carrier_id' => null,
                'status' => $row['status'] === 'in_transit' ? 'in_transit' : $row['status'],
                'attempts' => 0,
                'delivered_at' => $row['delivered_at'],
                ...$this->timestamps($row),
            ]);
        }
    }

    protected function migrateDeliveryAssignments(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $target = $this->subOrder($table, $row);

            if ($target === null) {
                continue;
            }

            [$code, $subOrderId] = $target;
            $shipmentId = $row['shipment_id'] ? $this->ids->find('shipments', (string) $row['shipment_id'], $code, 'shipments') : null;
            $agentId = $this->ids->find('delivery_agents', (string) $row['agent_id'], $code, 'delivery_agents');

            if ($shipmentId === null || $agentId === null) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', $shipmentId === null ? 'shipment not migrated' : 'agent not migrated to '.$code, $code, $row);

                continue;
            }

            if (! $row['delivery_otp']) {
                $this->quarantine->put($table, $id, 'shape_unsupported', 'assignment without a delivery code', $code, $row);

                continue;
            }

            $this->writer->upsert($table, $id, $code, 'delivery_assignments', [
                'number' => 'LEG-'.mb_substr($id, 0, 36),
                'shipment_id' => $shipmentId,
                'sub_order_id' => $subOrderId,
                'delivery_agent_id' => $agentId,
                'attempt_no' => 1,
                'status' => $row['status'],
                'delivery_code' => mb_substr((string) $row['delivery_otp'], 0, 8),
                'assigned_via' => 'manual',
                'currency' => $this->countries->currencyOf($code),
                'cod_amount' => $row['cod_amount_collected'],
                'accepted_at' => $row['accepted_at'],
                'picked_up_at' => $row['picked_up_at'],
                'delivered_at' => $row['delivered_at'],
                'failed_at' => $row['failed_at'],
                'failure_reason' => $row['failure_reason'] === null ? null : (self::FAILURE_REASONS[$row['failure_reason']] ?? 'other'),
                'failure_note' => $row['failure_notes'] === null ? null : mb_substr((string) $row['failure_notes'], 0, 255),
                'created_at' => $row['assigned_at'] ?? $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]);
            $this->context->sum($table, $this->countries->currencyOf($code), $row['cod_amount_collected']);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: int}|null
     */
    private function subOrder(string $table, array $row): ?array
    {
        $targets = $this->ids->targets('sub_orders', (string) $row['sub_order_id'], 'sub_orders');

        if ($targets === []) {
            $subOrder = $this->source->find('sub_orders', (string) $row['sub_order_id']);
            $parentQuarantined = $subOrder !== null && $this->quarantine->has('orders', (string) $subOrder['order_id']);
            $this->quarantine->put($table, (string) $row['id'], $parentQuarantined ? 'parent_quarantined' : 'parent_unmigrated', 'sub-order '.$row['sub_order_id'].' not migrated', null, $row);

            return null;
        }

        $code = array_key_first($targets);

        if (! $this->context->allows($code)) {
            $this->context->count($table, 'skipped_other_country');

            return null;
        }

        return [$code, $targets[$code]];
    }
}
