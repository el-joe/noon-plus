<?php

namespace App\Domains\Shipping\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Listings\Models\Listing;
use App\Domains\Settings\SettingsService;
use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Models\ShippingZone;
use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * Prices and dates one seller's shipment (SRS §8): every active method of the
 * destination zone that the items allow, the customer's chosen method (or
 * standard, or the cheapest), and the flat per-seller fee when no zone or
 * rate is configured.
 */
class ShipmentQuoter
{
    public function __construct(
        private BillableWeight $billableWeight,
        private ShippingFeeCalculator $fees,
        private DeliveryEstimator $estimator,
        private SettingsService $settings,
    ) {}

    /**
     * @param  array<int, array{listing: Listing, quantity: int}>  $lines  listings with variant.product loaded
     */
    public function quote(Country $country, ?ShippingZone $zone, int $vendorId, array $lines, ShippingMethod $wanted, Money $flatFee, CarbonInterface $now): ShipmentQuote
    {
        $processingDays = $this->processingDays($lines);
        $options = $zone === null ? [] : $this->options($country, $zone, $vendorId, $lines, $processingDays, $flatFee->getCurrency()->getCurrencyCode(), $now);

        if ($options === []) {
            $transit = config('nawy.shipping.fallback_transit_days');

            return new ShipmentQuote(
                ShippingMethod::Standard,
                null,
                $flatFee,
                $this->estimator->estimate($country, $now, $processingDays, (int) $transit['min'], (int) $transit['max']),
                false,
                '0',
                [],
                true,
            );
        }

        $chosen = $options[$wanted->value] ?? $options[ShippingMethod::Standard->value] ?? collect($options)->sortBy(fn (array $option): string => (string) $option['fee']->getAmount())->first();

        return new ShipmentQuote(
            $chosen['method'],
            $zone->id,
            $chosen['fee'],
            $chosen['eta'],
            $zone->is_exceptional && $zone->optedInVendors()->whereKey($vendorId)->exists(),
            (string) $zone->vendor_subsidy_share_percent,
            array_values($options),
            false,
        );
    }

    /**
     * Seller handling days plus product lead time, the slowest item wins.
     *
     * @param  array<int, array{listing: Listing, quantity: int}>  $lines
     */
    public function processingDays(array $lines): int
    {
        return (int) collect($lines)->max(fn (array $line): int => (int) $line['listing']->handling_days + (int) $line['listing']->variant->product->lead_days);
    }

    /**
     * @param  array<int, array{listing: Listing, quantity: int}>  $lines
     * @return array<string, array{method: ShippingMethod, fee: Money, eta: DeliveryEstimate}>
     */
    private function options(Country $country, ShippingZone $zone, int $vendorId, array $lines, int $processingDays, string $currency, CarbonInterface $now): array
    {
        $divisor = (int) $this->settings->get('shipping.volumetric_divisor', config('nawy.shipping.volumetric_divisor'), $country);
        $grams = $this->billableWeight->grams(array_map(fn (array $line): array => ['variant' => $line['listing']->variant, 'quantity' => $line['quantity']], $lines), $divisor);
        $fulfilments = array_values(array_unique(array_map(fn (array $line): string => (string) $line['listing']->fulfilment, $lines)));
        $options = [];

        foreach ($zone->rates->sortBy(fn ($rate): int => array_search($rate->method, ShippingMethod::cases(), true)) as $rate) {
            $eta = $this->estimator->estimate($country, $now, $processingDays, $rate->transit_min_days, $rate->transit_max_days, $rate->cutoff_time);

            if ($rate->method->needsZeroProcessingDays() && ($processingDays > 0 || $eta->orderBefore === null)) {
                continue;
            }

            $options[$rate->method->value] = [
                'method' => $rate->method,
                'fee' => $this->fees->fee($rate, $grams, $currency, $vendorId, $fulfilments),
                'eta' => $eta,
            ];
        }

        return $options;
    }
}
