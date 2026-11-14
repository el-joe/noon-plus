<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping engine (SRS §8, FR-CHK-2) — country database:
 * zones (cities/areas) → rates per method (base + per kg, transit days)
 * → weight slabs → surcharges; exceptional zones with vendor opt-in.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_exceptional')->default(false);
            $table->decimal('vendor_subsidy_share_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_zone_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            $table->string('city', 120);
            $table->string('area', 120)->nullable();
            $table->timestamps();

            $table->index(['city', 'area']);
        });

        Schema::create('shipping_zone_vendor_opt_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shipping_zone_id', 'vendor_id']);
        });

        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            $table->string('method', 16);
            $table->decimal('base_fee', 19, 4);
            $table->decimal('base_weight_kg', 8, 3)->default(0);
            $table->decimal('per_kg_fee', 19, 4)->default(0);
            $table->unsignedTinyInteger('transit_min_days')->default(1);
            $table->unsignedTinyInteger('transit_max_days')->default(3);
            $table->time('cutoff_time')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shipping_zone_id', 'method']);
        });

        Schema::create('shipping_weight_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_rate_id')->constrained()->cascadeOnDelete();
            $table->decimal('max_kg', 8, 3);
            $table->decimal('fee', 19, 4);
            $table->timestamps();

            $table->unique(['shipping_rate_id', 'max_kg']);
        });

        Schema::create('shipping_surcharges', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('shipping_zone_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('method', 16)->nullable();
            $table->string('fulfilment', 8)->nullable();
            $table->string('type', 8);
            $table->decimal('value', 19, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('length_cm', 8, 2)->nullable()->after('weight_grams');
            $table->decimal('width_cm', 8, 2)->nullable()->after('length_cm');
            $table->decimal('height_cm', 8, 2)->nullable()->after('width_cm');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->string('destination_city', 120)->nullable()->after('guest_token');
            $table->string('destination_area', 120)->nullable()->after('destination_city');
            $table->string('shipping_method', 16)->default('standard')->after('destination_area');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn(['destination_city', 'destination_area', 'shipping_method']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['length_cm', 'width_cm', 'height_cm']);
        });

        Schema::dropIfExists('shipping_surcharges');
        Schema::dropIfExists('shipping_weight_slabs');
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_zone_vendor_opt_ins');
        Schema::dropIfExists('shipping_zone_areas');
        Schema::dropIfExists('shipping_zones');
    }
};
