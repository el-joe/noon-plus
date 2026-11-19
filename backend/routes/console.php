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
| AutoCompleteOrdersJob (SRS §17, QC-038): checked every 15 minutes; each
| country runs once a day at its `order.auto_complete_time` setting (country
| override through SettingsService, default 02:00), admin (Oman) time.
*/
Schedule::command('nawy:orders:auto-complete --scheduled')->everyFifteenMinutes()->withoutOverlapping();

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
Schedule::command('nawy:delivery:issue-payslips')->dailyAt('02:30')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();

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
Schedule::command('nawy:travel:expire-promotions')->everyFiveMinutes()->withoutOverlapping();
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

/*
| QA wave 3 (QC-015): SRS jobs that were missing. Daily times in the admin
| (Oman) time zone; every job runs per country through ForEachCountry and is
| idempotent. Banner / page / homepage-media schedules are covered by the
| page builder's per-block visibility window (checked on every render) and
| `nawy:content:publish-scheduled`; FBN jobs are at the end of this file; reporting-ETL, acquisition
| commissions, vendor subscriptions and the celebrity report belong to
| modules that are not built yet (see docs/project/qa-issues-qc.md QC-015).
*/
Schedule::command('nawy:coupons:expire')->dailyAt('00:15')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:analytics:aggregate')->hourly()->withoutOverlapping();
Schedule::command('nawy:analytics:prune')->dailyAt('04:30')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:catalog:rank-bestsellers')->weeklyOn(1, '03:30')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();

// QC-007 (lifecycle §9.3): resolved support tickets close after `support.auto_close_days` (default 7).
Schedule::command('nawy:support:auto-close')->hourly()->withoutOverlapping();

/*
| Fulfilled by Nawy (MASTER_PLAN §11): 01:00 storage snapshot + capacity
| overage, 01:15 long-term / unsellable flags, weekly inbound reminder,
| monthly storage invoices (previous month, on the 1st).
*/
Schedule::command('nawy:fbn:storage-snapshot')->dailyAt('01:00')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:fbn:flag-storage')->dailyAt('01:15')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:fbn:inbound-reminders')->weeklyOn(0, '09:00')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:fbn:invoice-storage')->monthlyOn(1, '02:30')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();

/*
| Reporting (FR-CTY-10, FR-ANL-2): nightly ETL into `nawy_reporting`
| (yesterday in each country's own time zone) and the celebrity monthly
| report for the previous month on the 1st, after the cross-promotion
| evaluation at 05:00. Admin (Oman) time.
*/
Schedule::command('nawy:reporting:etl')->dailyAt(config('nawy.analytics.reporting_etl_time'))->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:marketers:celebrity-reports')->monthlyOn(1, config('nawy.analytics.celebrity_report_time'))->timezone(config('nawy.admin_timezone'))->withoutOverlapping();

/*
| Finance (FR-FIN-6, FR-FIN-7): acquisition commissions for the previous
| month on the 1st at 02:00; vendor plan billing daily (renewals invoice each
| subscription's own monthly / annual period, past_due after the due date,
| expiry after `subscription.grace_days`). Admin (Oman) time, per country.
*/
Schedule::command('nawy:finance:acquisition-commissions')->monthlyOn(1, '02:00')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:subscriptions:bill')->dailyAt(config('nawy.finance.subscription_billing_time'))->timezone(config('nawy.admin_timezone'))->withoutOverlapping();

/*
| QC-015 search index rebuild: the products' normalised search text (Arabic
| letter folding, brand / category names, SKUs and barcodes) is rebuilt
| nightly per country; saves keep it current during the day.
*/
Schedule::command('nawy:search:rebuild-index')->dailyAt('04:20')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();

// PayPal USD conversion: exchange rates from the configured provider (manual driver = admins only; nothing fetched).
Schedule::command('nawy:exchange-rates:sync')->dailyAt(config('nawy.exchange_rates.sync_time'))->timezone(config('nawy.admin_timezone'))->withoutOverlapping();

// Email system: scheduled reminder emails (respect email preferences; idempotent per entity + window).
Schedule::command('nawy:emails:reminders seller-sla')->hourly()->withoutOverlapping();
Schedule::command('nawy:emails:reminders abandoned-carts')->hourly()->withoutOverlapping();
Schedule::command('nawy:emails:reminders document-expiry')->dailyAt('08:00')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:emails:reminders renewal-reminders')->dailyAt('08:15')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:emails:reminders credit-expiry')->dailyAt('08:30')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:emails:reminders review-requests')->dailyAt('10:00')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
Schedule::command('nawy:emails:reminders promotion-expiry')->dailyAt('09:00')->timezone(config('nawy.admin_timezone'))->withoutOverlapping();
