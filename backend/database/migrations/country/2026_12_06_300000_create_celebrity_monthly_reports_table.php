<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-ANL-2 / FR-MKT-18: the celebrity (influencer) monthly report — one row
 * per marketer per month with the month's figures as a snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('celebrity_monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketer_profile_id')->constrained();
            $table->char('period', 7);
            $table->char('currency', 3);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('units_sold')->default(0);
            $table->decimal('attributed_sales', 19, 4)->default(0);
            $table->unsignedInteger('conversions_count')->default(0);
            $table->decimal('commission_net', 19, 4)->default(0);
            $table->decimal('bonus_total', 19, 4)->default(0);
            $table->decimal('fixed_fees', 19, 4)->default(0);
            $table->decimal('total_earnings', 19, 4)->default(0);
            $table->unsignedInteger('referral_clicks')->default(0);
            $table->json('metrics');
            $table->unsignedBigInteger('generated_by_user_id')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            $table->unique(['marketer_profile_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('celebrity_monthly_reports');
    }
};
