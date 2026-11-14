<?php

namespace App\Domains\Shipping\Support;

use App\Domains\Catalog\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Billable weight (SRS §8): per item the higher of the actual weight and the
 * volumetric weight L × W × H (cm) ÷ divisor (cm³ per kg), times quantity.
 */
class BillableWeight
{
    /**
     * @param  iterable<array{variant: ProductVariant, quantity: int}>  $lines
     */
    public function grams(iterable $lines, int $volumetricDivisor): int
    {
        $total = 0;

        foreach ($lines as $line) {
            $total += max((int) $line['variant']->weight_grams, $this->volumetricGrams($line['variant'], $volumetricDivisor)) * $line['quantity'];
        }

        return $total;
    }

    public function volumetricGrams(ProductVariant $variant, int $volumetricDivisor): int
    {
        if ($variant->length_cm === null || $variant->width_cm === null || $variant->height_cm === null || $volumetricDivisor <= 0) {
            return 0;
        }

        return BigDecimal::of((string) $variant->length_cm)
            ->multipliedBy((string) $variant->width_cm)
            ->multipliedBy((string) $variant->height_cm)
            ->multipliedBy(1000)
            ->dividedBy($volumetricDivisor, 0, RoundingMode::Up)
            ->toInt();
    }
}
