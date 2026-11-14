<?php

use Illuminate\Support\Facades\Schedule;

/*
| Platform schedule (MASTER_PLAN §11). Country jobs run through
| App\Shared\Scheduling\ForEachCountry and are added per milestone.
*/
Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('nawy:cart:release-expired-locks')->everyMinute()->withoutOverlapping();

Schedule::command('nawy:flash-sales:transition')->everyFiveMinutes()->withoutOverlapping();

/*
| AutoCompleteOrdersJob (SRS §17): settings key `order.auto_complete_time`,
| a global time read in the admin (Oman) time zone.
*/
Schedule::command('nawy:orders:auto-complete')
    ->dailyAt(config('nawy.orders.auto_complete_time'))
    ->timezone(config('nawy.admin_timezone'))
    ->withoutOverlapping();
