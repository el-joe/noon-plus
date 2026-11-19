<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acquisition commissions (FR-FIN-6, SRS §4.1.5, §9; settings catalogue
 * `acquisition_commission_rules`): who brought a vendor in, the per-country
 * rules (rate inside a monthly min/max sales band, valid for a date range,
 * optionally for one acquirer) and the monthly commission rows with their
 * lifecycle (pending → approved → paid | rejected, history in
 * `state_transitions`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->unsignedBigInteger('acquired_by_user_id')->nullable()->index();
            $table->timestamp('acquired_at')->nullable();
        });

        Schema::create('acquisition_commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedBigInteger('acquirer_user_id')->nullable()->index();
            $table->decimal('min_monthly_sales', 19, 4)->default(0);
            $table->decimal('max_monthly_sales', 19, 4)->nullable();
            $table->decimal('rate_percent', 7, 4);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('acquisition_commissions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->nullable()->unique();
            $table->unsignedBigInteger('acquirer_user_id')->index();
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('acquisition_commission_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->decimal('sales_amount', 19, 4);
            $table->unsignedInteger('sub_orders_count')->default(0);
            $table->decimal('rate_percent', 7, 4);
            $table->decimal('amount', 19, 4);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('bank_reference', 120)->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_commissions');
        Schema::dropIfExists('acquisition_commission_rules');
        Schema::table('vendors', fn (Blueprint $table) => $table->dropColumn(['acquired_by_user_id', 'acquired_at']));
    }
};
