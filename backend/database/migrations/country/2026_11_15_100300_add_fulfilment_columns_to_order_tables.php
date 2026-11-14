<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots of shipping, ETA, warranty, flash sale and return window on
 * orders (FR-CHK-2, SRS §7–§8, §10) plus warranty purchases — country database.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('return_window_days')->nullable()->after('commission_rate');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('shipping_subsidy_total', 19, 4)->default(0)->after('shipping_total');
            $table->decimal('warranty_total', 19, 4)->default(0)->after('cod_fee');
        });

        Schema::table('sub_orders', function (Blueprint $table) {
            $table->string('shipping_method', 16)->nullable()->after('shipping_total');
            $table->foreignId('shipping_zone_id')->nullable()->after('shipping_method')->constrained()->nullOnDelete();
            $table->decimal('shipping_cost', 19, 4)->default(0)->after('shipping_zone_id');
            $table->decimal('shipping_subsidy_vendor', 19, 4)->default(0)->after('shipping_cost');
            $table->decimal('shipping_subsidy_platform', 19, 4)->default(0)->after('shipping_subsidy_vendor');
            $table->decimal('shipping_absorbed', 19, 4)->default(0)->after('shipping_subsidy_platform');
            $table->decimal('warranty_total', 19, 4)->default(0)->after('shipping_absorbed');
            $table->date('estimated_delivery_from')->nullable()->after('total');
            $table->date('estimated_delivery_by')->nullable()->after('estimated_delivery_from');

            $table->index(['status', 'delivered_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('regular_unit_price', 19, 4)->nullable()->after('unit_price');
            $table->foreignId('flash_sale_submission_id')->nullable()->after('line_total')->constrained()->nullOnDelete();
            $table->foreignId('warranty_plan_id')->nullable()->after('flash_sale_submission_id')->constrained()->nullOnDelete();
            $table->decimal('warranty_unit_price', 19, 4)->default(0)->after('warranty_plan_id');
            $table->decimal('warranty_total', 19, 4)->default(0)->after('warranty_unit_price');
            $table->unsignedSmallInteger('return_window_days')->nullable()->after('warranty_total');
        });

        Schema::create('warranty_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warranty_plan_id')->constrained();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 19, 4);
            $table->decimal('total', 19, 4);
            $table->unsignedSmallInteger('duration_months');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warranty_purchases');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flash_sale_submission_id');
            $table->dropConstrainedForeignId('warranty_plan_id');
            $table->dropColumn(['regular_unit_price', 'warranty_unit_price', 'warranty_total', 'return_window_days']);
        });

        Schema::table('sub_orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'delivered_at']);
            $table->dropConstrainedForeignId('shipping_zone_id');
            $table->dropColumn([
                'shipping_method', 'shipping_cost', 'shipping_subsidy_vendor', 'shipping_subsidy_platform',
                'shipping_absorbed', 'warranty_total', 'estimated_delivery_from', 'estimated_delivery_by',
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_subsidy_total', 'warranty_total']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('return_window_days');
        });
    }
};
