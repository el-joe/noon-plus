<?php

namespace App\Domains\Delivery\Support;

use App\Shared\Settings\VerticalSettings;

/**
 * Delivery settings of the current country (settings catalogue §7): key
 * "delivery.{key}" overrides `config('nawy.delivery.{key}')`.
 */
class DeliverySettings
{
    public function __construct(private VerticalSettings $settings) {}

    public function codeLength(): int
    {
        return max(4, $this->settings->int('delivery.code_length'));
    }

    public function proofRequiredForCod(): bool
    {
        return (bool) $this->settings->get('delivery.proof_required_for_cod');
    }

    /**
     * Sub-order total from which proof is needed even when prepaid; null = off.
     */
    public function proofRequiredAbove(): ?string
    {
        $value = $this->settings->get('delivery.proof_required_above');

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * photo | signature | either
     */
    public function proofType(): string
    {
        $type = $this->settings->string('delivery.proof_type');

        return in_array($type, ['photo', 'signature', 'either'], true) ? $type : 'either';
    }

    public function slaMinutes(): int
    {
        return max(1, $this->settings->int('delivery.sla_minutes'));
    }

    public function maxAttempts(): int
    {
        return max(1, $this->settings->int('delivery.max_attempts'));
    }

    public function rescheduleMaxDays(): int
    {
        return max(1, $this->settings->int('delivery.reschedule_max_days'));
    }

    /**
     * @return array<int, string>
     */
    public function failureReasons(): array
    {
        return array_values((array) $this->settings->get('delivery.failure_reasons'));
    }

    /**
     * off | nearest | round_robin
     */
    public function autoAssign(): string
    {
        return $this->settings->string('delivery.auto_assign');
    }

    public function proofMaxKilobytes(): int
    {
        return $this->settings->int('delivery.proof_max_kb');
    }
}
