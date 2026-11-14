<?php

namespace App\Domains\Shipping\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Models\Listing;
use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Models\ShippingZone;

/**
 * "Get it by …" on product pages: standard delivery to the country's default
 * zone (the customer's address is not known yet), or the fallback transit
 * days when no zone is set up. The default zone is looked up once.
 */
class ListingDeliveryPromise
{
    private bool $zoneLoaded = false;

    private ?ShippingZone $zone = null;

    public function __construct(
        private CountryContext $countryContext,
        private ZoneResolver $zones,
        private DeliveryEstimator $estimator,
        private ShipmentQuoter $shipments,
    ) {}

    /**
     * @param  Listing  $listing  with variant.product loaded
     */
    public function estimate(Listing $listing): DeliveryEstimate
    {
        if (! $this->zoneLoaded) {
            $this->zone = $this->zones->resolve(new ShippingDestination);
            $this->zoneLoaded = true;
        }

        $processingDays = $this->shipments->processingDays([['listing' => $listing, 'quantity' => 1]]);
        $rate = $this->zone?->rates->firstWhere('method', ShippingMethod::Standard) ?? $this->zone?->rates->first();
        $country = $this->countryContext->current();

        if ($rate === null) {
            $transit = config('nawy.shipping.fallback_transit_days');

            return $this->estimator->estimate($country, now(), $processingDays, (int) $transit['min'], (int) $transit['max']);
        }

        return $this->estimator->estimate($country, now(), $processingDays, $rate->transit_min_days, $rate->transit_max_days, $rate->cutoff_time);
    }
}
