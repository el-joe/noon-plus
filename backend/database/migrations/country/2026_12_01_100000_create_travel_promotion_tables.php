<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision D14 (travel: free listing, paid placement). The travel
 * placement is an `ad_slots` row (placement `travel`, `max_concurrent` =
 * how many promoted trips a list shows); its products are the duration
 * options with a price in this country's currency. A promotion is one
 * agency's paid window for one of its active packages, paid by card
 * through the payments domain (payable `travel_promotion`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_promotion_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_slot_id')->constrained('ad_slots');
            $table->string('code', 48)->unique();
            $table->string('name_en', 120);
            $table->string('name_ar', 120)->nullable();
            $table->unsignedSmallInteger('duration_days');
            $table->decimal('price', 19, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('travel_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->foreignId('travel_package_id')->constrained('travel_packages');
            $table->foreignId('travel_promotion_product_id')->constrained('travel_promotion_products');
            $table->foreignId('ad_slot_id')->constrained('ad_slots');
            $table->unsignedSmallInteger('duration_days');
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->decimal('vat_amount', 19, 4)->default(0);
            $table->string('status', 20)->default('pending_payment');
            $table->unsignedBigInteger('bought_by_user_id');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('impressions')->default(0);
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'ends_at']);
            $table->index(['travel_package_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_promotions');
        Schema::dropIfExists('travel_promotion_products');
    }
};
