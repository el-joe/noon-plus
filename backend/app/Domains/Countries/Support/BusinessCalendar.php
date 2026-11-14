<?php

namespace App\Domains\Countries\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Models\CountryCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Business days per country (FR-SHP-2, country-reference §6.3): a day is a
 * business day unless it is one of the country's weekend days
 * (`countries.weekend`) or inside a holiday in `country_calendars`.
 * Dates are the country's local calendar dates.
 */
class BusinessCalendar
{
    /**
     * @var array<string, array<string, true>>
     */
    private array $holidayDates = [];

    public function isBusinessDay(Country $country, CarbonInterface $localDate): bool
    {
        $date = CarbonImmutable::parse($localDate->toDateString(), $country->timezone);

        return ! in_array(strtolower($date->englishDayOfWeek), $country->weekend, true)
            && ! isset($this->holidaysAround($country, $date)[$date->toDateString()]);
    }

    /**
     * The given day when it is a business day, otherwise the next one.
     */
    public function onOrAfter(Country $country, CarbonInterface $localDate): CarbonImmutable
    {
        $date = CarbonImmutable::parse($localDate->toDateString(), $country->timezone);

        for ($guard = 0; $guard < 366 && ! $this->isBusinessDay($country, $date); $guard++) {
            $date = $date->addDay();
        }

        return $date;
    }

    /**
     * Move forward a number of business days from a business day.
     */
    public function addBusinessDays(Country $country, CarbonInterface $localDate, int $days): CarbonImmutable
    {
        $date = $this->onOrAfter($country, $localDate);

        for ($added = 0; $added < $days; $added++) {
            $date = $this->onOrAfter($country, $date->addDay());
        }

        return $date;
    }

    /**
     * Holiday dates in a ±1 year window around a date, loaded once per window.
     *
     * @return array<string, true>
     */
    private function holidaysAround(Country $country, CarbonImmutable $date): array
    {
        $cacheKey = $country->id.':'.$date->year;

        if (! isset($this->holidayDates[$cacheKey])) {
            $dates = [];
            $holidays = CountryCalendar::query()
                ->where('country_id', $country->id)
                ->where('ends_on', '>=', $date->startOfYear()->subMonth()->toDateString())
                ->where('starts_on', '<=', $date->endOfYear()->addYear()->toDateString())
                ->get(['starts_on', 'ends_on']);

            foreach ($holidays as $holiday) {
                for ($day = $holiday->starts_on; $day->lessThanOrEqualTo($holiday->ends_on); $day = $day->addDay()) {
                    $dates[$day->toDateString()] = true;
                }
            }

            $this->holidayDates[$cacheKey] = $dates;
        }

        return $this->holidayDates[$cacheKey];
    }
}
