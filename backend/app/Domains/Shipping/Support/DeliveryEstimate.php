<?php

namespace App\Domains\Shipping\Support;

use Carbon\CarbonImmutable;

/**
 * Delivery window in the country's local dates ("Get it by …"), plus the
 * moment the current dispatch day closes when ordering today still counts.
 */
final readonly class DeliveryEstimate
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $by,
        public ?CarbonImmutable $orderBefore,
    ) {}

    /**
     * @return array{from: string, by: string, order_before: string|null}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'by' => $this->by->toDateString(),
            'order_before' => $this->orderBefore?->utc()->toIso8601String(),
        ];
    }
}
