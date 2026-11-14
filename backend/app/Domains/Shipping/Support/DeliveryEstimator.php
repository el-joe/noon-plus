<?php

namespace App\Domains\Shipping\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\BusinessCalendar;
use App\Domains\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Delivery ETA in business days (FR-SHP-2): orders placed on a business day
 * before the cut-off (country local time) are dispatched from that day,
 * otherwise from the next business day. Processing days (seller handling)
 * and transit days are then counted in business days, skipping the
 * country's weekend and its holidays.
 */
class DeliveryEstimator
{
    public function __construct(
        private BusinessCalendar $calendar,
        private SettingsService $settings,
    ) {}

    public function estimate(Country $country, CarbonInterface $now, int $processingDays, int $transitMinDays, int $transitMaxDays, ?string $cutoffTime = null): DeliveryEstimate
    {
        $local = CarbonImmutable::instance($now)->setTimezone($country->timezone);
        $cutoff = $local->setTimeFromTimeString($cutoffTime ?? $this->defaultCutoff($country));
        $countsToday = $this->calendar->isBusinessDay($country, $local) && $local->lessThan($cutoff);
        $dispatchDay = $countsToday ? $local->startOfDay() : $this->calendar->onOrAfter($country, $local->addDay());

        return new DeliveryEstimate(
            $this->calendar->addBusinessDays($country, $dispatchDay, $processingDays + $transitMinDays),
            $this->calendar->addBusinessDays($country, $dispatchDay, $processingDays + max($transitMinDays, $transitMaxDays)),
            $countsToday ? $cutoff : null,
        );
    }

    private function defaultCutoff(Country $country): string
    {
        return (string) $this->settings->get('shipping.eta_cutoff_time', config('nawy.shipping.eta_cutoff_time'), $country);
    }
}
