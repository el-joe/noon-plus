<?php

namespace App\Shared\Scheduling;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs scheduled work once per active country with that country selected
 * (MASTER_PLAN §11: every job is country-aware). One country's failure is
 * logged and does not stop the others.
 */
class ForEachCountry
{
    public function __construct(private CountryContext $countryContext) {}

    /**
     * @param  callable(Country): void  $work
     * @return array<string, string> country code => "ok" | "failed"
     */
    public function run(string $jobName, callable $work): array
    {
        $results = [];

        Country::query()->active()->each(function (Country $country) use ($jobName, $work, &$results): void {
            try {
                $this->countryContext->runAs($country, $work);
                $results[$country->code] = 'ok';
            } catch (Throwable $exception) {
                $results[$country->code] = 'failed';
                Log::error("Scheduled job {$jobName} failed for {$country->code}", ['exception' => $exception]);
                report($exception);
            }
        });

        return $results;
    }
}
