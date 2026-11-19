<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-CTY-10 / FR-ANL-2 (ADR 2): the reporting database. Facts are loaded
 * nightly from each country database by `nawy:reporting:etl`, one row per
 * country × local day (× currency for money). Money is never summed across
 * currencies, so every money fact carries its currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etl_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch');
            $table->string('country_code', 3);
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status', 12)->default('running');
            $table->string('trigger', 12)->default('schedule');
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->unsignedInteger('rows_written')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();

            $table->index('batch');
            $table->index(['country_code', 'started_at']);
        });

        Schema::create('fact_sales_daily', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 3);
            $table->date('report_date');
            $table->char('currency', 3);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('sub_orders_count')->default(0);
            $table->unsignedInteger('items_sold')->default(0);
            $table->decimal('gmv', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('shipping_total', 19, 4)->default(0);
            $table->decimal('average_order_value', 19, 4)->default(0);
            $table->unsignedInteger('cancelled_orders')->default(0);
            $table->decimal('cancelled_value', 19, 4)->default(0);
            $table->unsignedInteger('unique_buyers')->default(0);
            $table->unsignedInteger('new_buyers')->default(0);
            $table->unsignedInteger('cod_orders')->default(0);
            $table->unsignedInteger('returns_requested')->default(0);
            $table->unsignedInteger('refunds_count')->default(0);
            $table->decimal('refunds_amount', 19, 4)->default(0);
            $table->timestamp('loaded_at');

            $table->unique(['country_code', 'report_date', 'currency'], 'fact_sales_daily_unique');
            $table->index('report_date');
        });

        Schema::create('fact_sellers_daily', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 3);
            $table->date('report_date');
            $table->unsignedInteger('active_sellers')->default(0);
            $table->unsignedInteger('new_sellers')->default(0);
            $table->unsignedInteger('selling_sellers')->default(0);
            $table->unsignedInteger('active_listings')->default(0);
            $table->timestamp('loaded_at');

            $table->unique(['country_code', 'report_date'], 'fact_sellers_daily_unique');
            $table->index('report_date');
        });

        Schema::create('fact_marketers_daily', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 3);
            $table->date('report_date');
            $table->char('currency', 3);
            $table->unsignedInteger('active_marketers')->default(0);
            $table->unsignedInteger('new_marketers')->default(0);
            $table->unsignedInteger('conversions_count')->default(0);
            $table->decimal('attributed_sales', 19, 4)->default(0);
            $table->decimal('commissions_net', 19, 4)->default(0);
            $table->unsignedInteger('reversals_count')->default(0);
            $table->decimal('withdrawals_processed', 19, 4)->default(0);
            $table->timestamp('loaded_at');

            $table->unique(['country_code', 'report_date', 'currency'], 'fact_marketers_daily_unique');
            $table->index('report_date');
        });

        Schema::create('fact_payouts_daily', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 3);
            $table->date('report_date');
            $table->char('currency', 3);
            $table->unsignedInteger('generated_count')->default(0);
            $table->decimal('generated_amount', 19, 4)->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->decimal('completed_amount', 19, 4)->default(0);
            $table->timestamp('loaded_at');

            $table->unique(['country_code', 'report_date', 'currency'], 'fact_payouts_daily_unique');
            $table->index('report_date');
        });

        Schema::create('fact_revenue_daily', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 3);
            $table->date('report_date');
            $table->char('currency', 3);
            $table->string('account_code', 40);
            $table->decimal('amount', 19, 4)->default(0);
            $table->timestamp('loaded_at');

            $table->unique(['country_code', 'report_date', 'currency', 'account_code'], 'fact_revenue_daily_unique');
            $table->index('report_date');
        });
    }

    public function down(): void
    {
        foreach (['fact_revenue_daily', 'fact_payouts_daily', 'fact_marketers_daily', 'fact_sellers_daily', 'fact_sales_daily', 'etl_runs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
