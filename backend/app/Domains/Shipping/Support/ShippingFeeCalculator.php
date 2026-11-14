<?php

namespace App\Domains\Shipping\Support;

use App\Domains\Shipping\Enums\SurchargeType;
use App\Domains\Shipping\Models\ShippingRate;
use App\Domains\Shipping\Models\ShippingSurcharge;
use App\Shared\Money\MoneyFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fee for one shipment on one rate (SRS §8):
 * - with weight slabs: the fee of the first slab the billable weight fits in;
 *   above the last slab, its fee plus the per-kg fee for every started kg over;
 * - without slabs: the base fee (covers `base_weight_kg`) plus the per-kg fee
 *   for every started kg over it;
 * then surcharges: percentages of that fee, then fixed amounts.
 */
class ShippingFeeCalculator
{
    /**
     * @var Collection<int, ShippingSurcharge>|null
     */
    private ?Collection $activeSurcharges = null;

    public function __construct(private MoneyFactory $money) {}

    /**
     * @param  array<int, string>  $fulfilments  fulfilment modes in the shipment (fbm, fbn)
     */
    public function fee(ShippingRate $rate, int $billableGrams, string $currency, int $vendorId, array $fulfilments): Money
    {
        $kg = BigDecimal::of($billableGrams)->dividedBy(1000, 3);
        $perKg = $this->money->of((string) $rate->per_kg_fee, $currency);
        $slabs = $rate->slabs;

        if ($slabs->isNotEmpty()) {
            $slab = $slabs->first(fn ($slab): bool => $kg->isLessThanOrEqualTo((string) $slab->max_kg));
            $last = $slabs->last();
            $fee = $slab !== null
                ? $this->money->of((string) $slab->fee, $currency)
                : $this->money->of((string) $last->fee, $currency)->plus($perKg->multipliedBy($this->startedKgOver($kg, (string) $last->max_kg)));
        } else {
            $fee = $this->money->of((string) $rate->base_fee, $currency)
                ->plus($perKg->multipliedBy($this->startedKgOver($kg, (string) $rate->base_weight_kg)));
        }

        return $this->withSurcharges($fee, $rate, $vendorId, $fulfilments);
    }

    private function startedKgOver(BigDecimal $kg, string $includedKg): int
    {
        $over = $kg->minus($includedKg);

        return $over->isPositive() ? $over->dividedBy(1, 0, RoundingMode::Up)->toInt() : 0;
    }

    /**
     * @param  array<int, string>  $fulfilments
     */
    private function withSurcharges(Money $fee, ShippingRate $rate, int $vendorId, array $fulfilments): Money
    {
        $this->activeSurcharges ??= ShippingSurcharge::query()->where('is_active', true)->orderBy('id')->get();

        $surcharges = $this->activeSurcharges->filter(fn (ShippingSurcharge $surcharge): bool => ($surcharge->shipping_zone_id === null || (int) $surcharge->shipping_zone_id === (int) $rate->shipping_zone_id)
            && ($surcharge->vendor_id === null || (int) $surcharge->vendor_id === $vendorId)
            && ($surcharge->method === null || $surcharge->method === $rate->method)
            && ($surcharge->fulfilment === null || in_array($surcharge->fulfilment, $fulfilments, true)));

        $total = $fee;

        foreach ($surcharges as $surcharge) {
            $total = $total->plus($surcharge->type === SurchargeType::Percent
                ? $this->money->percentageOf($fee, (string) $surcharge->value)
                : $this->money->of((string) $surcharge->value, $fee->getCurrency()->getCurrencyCode()));
        }

        return $total;
    }
}
