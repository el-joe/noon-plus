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
| ExpirePendingPaymentsJob (SRS FR-CHK-3): unpaid card/BNPL orders past
| `payment.pending_expiry_minutes` are cancelled and their stock released.
*/
Schedule::command('nawy:payments:expire-pending')->everyFiveMinutes()->withoutOverlapping();

/*
| AutoCompleteOrdersJob (SRS §17): settings key `order.auto_complete_time`,
| a global time read in the admin (Oman) time zone.
*/
Schedule::command('nawy:orders:auto-complete')
    ->dailyAt(config('nawy.orders.auto_complete_time'))
    ->timezone(config('nawy.admin_timezone'))
    ->withoutOverlapping();

/*
| Money (WP4, MASTER_PLAN §11, SRS §17). Daily times are read in the admin
| (Oman) time zone like auto-complete; every job runs per country and is
| idempotent.
*/
Schedule::command('nawy:wallet:expire-promotional')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('nawy:gift-cards:deliver-scheduled')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('nawy:gift-cards:expire')->dailyAt('00:30')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:payouts:generate')->dailyAt(config('nawy.finance.payout_generation_time'))->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:warranty:expire')->dailyAt('03:00')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:cod:generate-settlements')->dailyAt(config('nawy.finance.cod_settlement_time'))->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:membership:renew')->hourly()->withoutOverlapping();

/*
| Marketers, campaigns, contracts & documents (WP5, MASTER_PLAN §11, SRS
| §17). Daily times in the admin (Oman) time zone; every job runs per
| country (contracts are central) and is idempotent.
*/
Schedule::command('nawy:contracts:apply-effective')->hourly()->withoutOverlapping();
Schedule::command('nawy:campaigns:expire-invitations')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('nawy:campaigns:auto-approve')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('nawy:campaigns:monitor-stock')->hourly()->withoutOverlapping();
Schedule::command('nawy:conversions:approve')->dailyAt(config('nawy.marketing.approve_conversions_time'))->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:conversions:release')->dailyAt(config('nawy.marketing.release_commissions_time'))->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:campaigns:complete-ended')->dailyAt(config('nawy.marketing.campaign_end_time'))->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:documents:expire')->dailyAt('01:30')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:marketers:evaluate-cross-promotion')->monthlyOn(1, '05:00')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();

/*
| Verticals (WP6, MP §11, SRS §17, lifecycle catalogue Appendix B). Daily
| times in the admin (Oman) time zone; every job runs per country and is
| idempotent.
*/
Schedule::command('nawy:travel:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('nawy:ads:schedule-bookings')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('nawy:coupons:close-participation-invitations')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('nawy:classifieds:saved-search-alerts')->hourly()->withoutOverlapping();
Schedule::command('nawy:ad-packages:expire')->hourly()->withoutOverlapping();
Schedule::command('nawy:ads:reset-daily-budgets')->dailyAt('00:00')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:coupons:activate-participation-invitations')->dailyAt('00:30')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:exclusive-contracts:sync')->dailyAt('00:45')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:classifieds:expire')->dailyAt('01:00')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:travel:complete-bookings')->dailyAt(config('nawy.travel.completion_time'))->timezone(config('nawy.admin_timezone'))->withoutOverlapping();

/*
| Delivery, live media & content (WP7, MP §11, SRS §17): SLA breaches every
| 15 minutes, recorded channels every minute, page and blog schedules every
| 5 minutes; every job runs per country and is idempotent.
*/
Schedule::command('nawy:delivery:check-sla')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('nawy:streams:sync-recorded')->everyMinute()->withoutOverlapping();
Schedule::command('nawy:content:publish-scheduled')->everyFiveMinutes()->withoutOverlapping();

// QC-047: expired API tokens (per-type `expires_at`, legacy tokens past `sanctum.expiration`) are deleted daily.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('04:10')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
