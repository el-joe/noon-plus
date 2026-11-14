<?php

namespace App\Domains\Countries\Support;

use App\Domains\Countries\Models\Country;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Time rules (MASTER_PLAN ADR 14): store UTC, enter country-scoped times in the
 * country's local time, show admins the Oman equivalent.
 */
class CountryClock
{
    /**
     * Convert a wall-clock time typed in a country's local time to UTC.
     */
    public function localToUtc(Country $country, string $localDateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($localDateTime, $country->timezone)->utc();
    }

    /**
     * Express a moment in a country's local time.
     */
    public function inCountry(Country $country, CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone($country->timezone);
    }

    /**
     * Express a moment in the admin time zone (Oman).
     */
    public function inAdminTime(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(config('nawy.admin_timezone'));
    }

    /**
     * The "= … Oman time" helper label shown under country date-time inputs.
     * A "(+1)" or "(-1)" suffix marks a different calendar day.
     */
    public function adminTimeLabel(Country $country, string $localDateTime): string
    {
        $local = CarbonImmutable::parse($localDateTime, $country->timezone);
        $admin = $this->inAdminTime($local);

        $dayDifference = (int) CarbonImmutable::parse($local->toDateString())
            ->diffInDays(CarbonImmutable::parse($admin->toDateString()), false);
        $suffix = $dayDifference === 0 ? '' : sprintf(' (%+d)', $dayDifference);

        return '= '.$admin->format('g:i A').$suffix.' Oman time';
    }

    public function isWeekend(Country $country, CarbonInterface $moment): bool
    {
        $dayName = strtolower($this->inCountry($country, $moment)->englishDayOfWeek);

        return in_array($dayName, $country->weekend, true);
    }
}
