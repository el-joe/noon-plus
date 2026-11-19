<?php

namespace App\Domains\Orders\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Orders\Support\AutoCompleteDeliveredOrders;
use App\Domains\Settings\SettingsService;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * AutoCompleteOrdersJob (SRS §7.1, §17). With `--scheduled` (the schedule
 * runs it every 15 minutes) each country runs once a day, at or after its
 * own `order.auto_complete_time` setting (country override, else global,
 * else `nawy.orders.auto_complete_time`), read in the admin (Oman) time
 * zone (QC-038). Without the flag every country runs now.
 */
#[Signature('nawy:orders:auto-complete {--scheduled : Run each country once a day at its order.auto_complete_time}')]
#[Description('Complete delivered sub-orders whose return window has passed, in every country (daily at order.auto_complete_time, 02:00 by default)')]
class AutoCompleteOrdersCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, AutoCompleteDeliveredOrders $autoComplete, SettingsService $settings): int
    {
        $scheduled = (bool) $this->option('scheduled');

        $forEachCountry->run('auto-complete-orders', function (Country $country) use ($autoComplete, $settings, $scheduled): void {
            if ($scheduled && ! $this->isDue($country, $settings)) {
                return;
            }

            $this->components->info("{$country->code}: completed {$autoComplete->run($country)}");
        });

        return self::SUCCESS;
    }

    /**
     * Due once the country's time has passed today (admin time zone), and
     * only the first time that day.
     */
    private function isDue(Country $country, SettingsService $settings): bool
    {
        $now = now(config('nawy.admin_timezone'));
        $time = (string) $settings->get('order.auto_complete_time', config('nawy.orders.auto_complete_time'), $country);

        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) || $now->format('H:i') < $time) {
            return false;
        }

        return Cache::add("orders:auto-complete:{$country->code}:{$now->toDateString()}", true, $now->copy()->endOfDay());
    }
}
