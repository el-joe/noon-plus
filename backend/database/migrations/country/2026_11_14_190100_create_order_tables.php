<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders (FR-CHK-5, SRS §7): one order, one sub-order per seller, N items — country database.
 * Amounts are VAT-inclusive DECIMAL(19,4) in the country currency.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('status', 24)->default('placed');
            $table->string('payment_method', 24);
            $table->string('payment_status', 24)->default('pending');
            $table->char('currency', 3);
            $table->decimal('subtotal', 19, 4);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('shipping_total', 19, 4)->default(0);
            $table->decimal('cod_fee', 19, 4)->default(0);
            $table->decimal('grand_total', 19, 4);
            $table->decimal('tax_included', 19, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->json('shipping_address');
            $table->timestamp('placed_at');
            $table->timestamps();
        });

        Schema::create('sub_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained();
            $table->string('number', 40)->unique();
            $table->string('status', 24)->default('placed');
            $table->decimal('subtotal', 19, 4);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('shipping_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained();
            $table->string('sku', 64);
            $table->string('title');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->string('fulfillment_status', 24)->default('placed');
            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->decimal('discount_amount', 19, 4);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('sub_orders');
        Schema::dropIfExists('orders');
    }
};
