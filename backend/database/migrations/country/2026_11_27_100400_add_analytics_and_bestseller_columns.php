<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QC-015 scheduled jobs:
 * - `analytics_daily_metrics`: the hourly analytics rollup (SRS
 *   AggregateAnalyticsCacheJob) — one row per day × metric × key, so
 *   dashboards read a few rows instead of scanning orders and ad events;
 * - products: units sold in the last 30 days and the bestseller rank
 *   written by the weekly rankings job (SRS FR-CAT-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('metric', 48);
            $table->string('dimension', 64)->default('');
            $table->decimal('value', 19, 4)->default(0);
            $table->timestamp('computed_at')->nullable();

            $table->unique(['day', 'metric', 'dimension']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('units_sold_30d')->default(0)->after('status');
            $table->unsignedInteger('bestseller_rank')->nullable()->after('units_sold_30d');
            $table->index('bestseller_rank');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['bestseller_rank']);
            $table->dropColumn(['units_sold_30d', 'bestseller_rank']);
        });
        Schema::dropIfExists('analytics_daily_metrics');
    }
};
