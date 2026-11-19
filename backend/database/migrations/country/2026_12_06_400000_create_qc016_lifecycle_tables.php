<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QC-016 lifecycles that had no table: product requests (§1.3), profile
 * change requests (§7.3), packaging supplies and orders (§6.3) and the
 * flash-sale marketer invite sub-machine (§4.10). History rows go to
 * `state_transitions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->string('requester_type', 16);
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('marketer_profile_id')->nullable()->index();
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('title_en');
            $table->string('title_ar')->nullable();
            $table->string('brand_name', 120)->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('gtin', 32)->nullable()->index();
            $table->string('model_number', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('reference_url', 500)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->string('requester_type', 16);
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('section', 24);
            $table->json('current_values')->nullable();
            $table->json('requested_values');
            $table->text('note')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->boolean('bank_verified')->default(false);
            $table->json('applied_diff')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'section', 'status']);
        });

        Schema::create('packaging_supplies', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 48)->unique();
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->string('description_en', 500)->nullable();
            $table->string('description_ar', 500)->nullable();
            $table->decimal('unit_cost', 19, 4);
            $table->unsignedInteger('min_quantity')->default(1);
            $table->unsignedInteger('max_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('packaging_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('ordered_by_user_id');
            $table->string('status', 16)->default('pending')->index();
            $table->string('currency', 3);
            $table->decimal('items_total', 19, 4);
            $table->decimal('delivery_fee', 19, 4)->default(0);
            $table->decimal('total', 19, 4);
            $table->json('delivery_address')->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('carrier_name', 64)->nullable();
            $table->string('tracking_number', 64)->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('packaging_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('packaging_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('packaging_supply_id');
            $table->string('sku', 48);
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->decimal('unit_cost', 19, 4);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 19, 4);
            $table->timestamps();
        });

        Schema::create('flash_sale_marketer_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_event_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('marketer_profile_id')->index();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['flash_sale_event_id', 'marketer_profile_id'], 'flash_sale_invites_event_marketer_unique');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->text('search_text')->nullable();
            $table->timestamp('search_indexed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['search_text', 'search_indexed_at']));
        Schema::dropIfExists('flash_sale_marketer_invites');
        Schema::dropIfExists('packaging_order_items');
        Schema::dropIfExists('packaging_orders');
        Schema::dropIfExists('packaging_supplies');
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('product_requests');
    }
};
