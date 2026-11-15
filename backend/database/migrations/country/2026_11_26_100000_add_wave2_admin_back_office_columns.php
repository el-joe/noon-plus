<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA wave 2 — admin back office (country database):
 * - categories: default size guide; category attributes and filters;
 * - brands: Arabic name;
 * - coupons (QC-014): funding, targets (category / product / brand /
 *   vendor), first order, customer segment and BOGO quantities;
 *   redemptions keep who funded them and each vendor's share;
 * - vendors (QC-019): the assigned account manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('size_guide_template_id')->nullable()->after('image_path');
            $table->text('description_en')->nullable()->after('name_ar');
            $table->text('description_ar')->nullable()->after('description_en');
        });

        Schema::create('category_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('key', 48);
            $table->string('label_en', 120);
            $table->string('label_ar', 120);
            $table->string('type', 16)->default('text');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'key']);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('name');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->string('description')->nullable()->after('code');
            $table->string('funded_by', 16)->default('platform')->after('vendor_id');
            $table->string('target_type', 16)->default('all')->after('funded_by');
            $table->json('target_ids')->nullable()->after('target_type');
            $table->boolean('first_order_only')->default(false)->after('target_ids');
            $table->string('customer_segment', 24)->default('everyone')->after('first_order_only');
            $table->unsignedSmallInteger('buy_quantity')->nullable()->after('value');
            $table->unsignedSmallInteger('get_quantity')->nullable()->after('buy_quantity');
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('is_active');
        });

        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->string('funded_by', 16)->nullable()->after('discount_amount');
            $table->json('vendor_shares')->nullable()->after('funded_by');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->unsignedBigInteger('account_manager_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $table) => $table->dropColumn('account_manager_id'));
        Schema::table('coupon_redemptions', fn (Blueprint $table) => $table->dropColumn(['funded_by', 'vendor_shares']));
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn([
            'description', 'funded_by', 'target_type', 'target_ids', 'first_order_only', 'customer_segment', 'buy_quantity', 'get_quantity', 'created_by_user_id',
        ]));
        Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('name_ar'));
        Schema::dropIfExists('category_attributes');
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn(['size_guide_template_id', 'description_en', 'description_ar']));
    }
};
