<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor subscriptions (FR-FIN-7, SRS §4.1.12, §9; lifecycle catalogue §3.6):
 * plans priced in the country currency, the vendor's subscription
 * (trialing → active → past_due → cancelled | expired, history in
 * `state_transitions`) and one invoice per billed period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->string('interval', 16);
            $table->decimal('price', 19, 4);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedInteger('max_active_listings')->nullable();
            $table->json('features')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vendor_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('vendor_subscription_plan_id')->constrained();
            $table->foreignId('pending_plan_id')->nullable()->constrained('vendor_subscription_plans')->nullOnDelete();
            $table->string('status', 16)->index();
            $table->string('payment_preference', 16)->default('payout');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('past_due_since')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });

        Schema::create('vendor_subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('vendor_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('vendor_subscription_plan_id')->constrained();
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->decimal('vat', 19, 4)->default(0);
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_via', 16)->nullable();
            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_subscription_id', 'period_start'], 'vendor_sub_invoices_subscription_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_subscription_invoices');
        Schema::dropIfExists('vendor_subscriptions');
        Schema::dropIfExists('vendor_subscription_plans');
    }
};
