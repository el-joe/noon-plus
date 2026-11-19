<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Fulfilment\Enums\RemovalType;
use App\Domains\Listings\Models\Listing;
use App\Domains\Shipping\Support\BillableWeight;
use App\Shared\Money\MoneyFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * FBN fee maths (help centre "FBN fees", fee table from {@see FbnSettings}):
 *
 * - Outbound (fulfilment) fee per unit: the tier of the unit's billable
 *   weight (actual or L×W×H/5000, the higher), `low` when the unit price is
 *   at or under the price threshold, `high` above it; heavier than the last
 *   tier adds `outbound_extra_per_kg` per started kg. Charged in the
 *   sub-order settlement.
 * - Storage: average cubic feet stored over the month × rate per cubic foot
 *   (storage, long-term, unsellable). Charged by the monthly invoice.
 * - Removal: return to seller = units × per unit + billable kg × per kg;
 *   disposal = units × per unit; at least the minimum. Charged when done.
 *
 * Every amount is rounded half-up to the currency.
 */
class FbnFees
{
    public const CUBIC_CM_PER_CUBIC_FOOT = 28317;

    public function __construct(private FbnSettings $settings, private MoneyFactory $money, private BillableWeight $weight) {}

    public function unitCubicFeet(ProductVariant $variant): string
    {
        if ($variant->length_cm === null || $variant->width_cm === null || $variant->height_cm === null) {
            return $this->settings->string('default_unit_cubic_feet');
        }

        return (string) BigDecimal::of((string) $variant->length_cm)->multipliedBy((string) $variant->width_cm)->multipliedBy((string) $variant->height_cm)
            ->dividedBy(self::CUBIC_CM_PER_CUBIC_FOOT, 6, RoundingMode::HalfUp);
    }

    public function billableGrams(ProductVariant $variant): int
    {
        return max((int) $variant->weight_grams, $this->weight->volumetricGrams($variant, $this->settings->int('removal_volumetric_divisor')));
    }

    /**
     * Outbound fee for one unit of the listing sold at `$unitPrice`.
     */
    public function outboundPerUnit(Listing $listing, string $unitPrice, string $currency): Money
    {
        $fees = $this->settings->fees();
        $grams = $this->billableGrams($listing->variant);
        $band = BigDecimal::of($unitPrice)->isGreaterThan($fees['outbound_price_threshold']) ? 'high' : 'low';
        $tiers = $fees['outbound_tiers'];

        if ($tiers === []) {
            return $this->money->zero($currency);
        }

        foreach ($tiers as $tier) {
            if ($grams <= $tier['max_grams']) {
                return $this->money->of($tier[$band], $currency);
            }
        }

        $last = $tiers[array_key_last($tiers)];
        $extraKg = (int) ceil(($grams - $last['max_grams']) / 1000);

        return $this->money->of($last[$band], $currency)->plus($this->money->of($fees['outbound_extra_per_kg'], $currency)->multipliedBy($extraKg, RoundingMode::HalfUp));
    }

    /**
     * Monthly fee for an average stored volume.
     *
     * @param  'storage'|'long_term'|'unsellable'  $kind
     */
    public function storage(string $cubicFeet, string $kind, string $currency): Money
    {
        $rate = $this->settings->fees()["{$kind}_per_cubic_foot"];

        return Money::of(BigDecimal::of($cubicFeet)->multipliedBy($rate)->toScale(8, RoundingMode::HalfUp), $currency, roundingMode: RoundingMode::HalfUp);
    }

    /**
     * Removal fee for lines of [listing, quantity].
     *
     * @param  iterable<array{listing: Listing, quantity: int}>  $lines
     */
    public function removal(RemovalType $type, iterable $lines, string $currency): Money
    {
        $fees = $this->settings->fees();
        $units = 0;
        $grams = 0;

        foreach ($lines as $line) {
            $units += $line['quantity'];
            $grams += $this->billableGrams($line['listing']->variant) * $line['quantity'];
        }

        if ($units === 0) {
            return $this->money->zero($currency);
        }

        $amount = $type === RemovalType::Disposal
            ? BigDecimal::of($fees['disposal_per_unit'])->multipliedBy($units)
            : BigDecimal::of($fees['removal_per_unit'])->multipliedBy($units)->plus(BigDecimal::of($fees['removal_per_kg'])->multipliedBy(BigDecimal::of($grams)->dividedBy(1000, 3, RoundingMode::Up)));

        $fee = Money::of($amount->toScale(8, RoundingMode::HalfUp), $currency, roundingMode: RoundingMode::HalfUp);
        $minimum = $this->money->of($fees['removal_minimum'], $currency);

        return $fee->isLessThan($minimum) ? $minimum : $fee;
    }
}
