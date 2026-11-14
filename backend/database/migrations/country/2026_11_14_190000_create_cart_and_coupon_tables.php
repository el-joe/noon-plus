<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cart with 15-minute stock locks (FR-CHK-1) and coupons (SRS §10) — country database.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_user_id')->nullable()->unique();
            $table->string('guest_token', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['cart_id', 'listing_id']);
        });

        Schema::create('cart_inventory_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['cart_id', 'listing_id']);
            $table->index(['listing_id', 'expires_at']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('scope', 16);
            $table->foreignId('vendor_id')->nullable()->constrained();
            $table->string('type', 16);
            $table->decimal('value', 19, 4)->default(0);
            $table->decimal('min_order_amount', 19, 4)->nullable();
            $table->decimal('max_discount_amount', 19, 4)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cart_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained();
            $table->timestamps();

            $table->unique(['cart_id', 'coupon_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_coupons');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('cart_inventory_locks');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
