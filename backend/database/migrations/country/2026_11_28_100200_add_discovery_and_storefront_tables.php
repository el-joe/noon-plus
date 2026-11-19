<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA wave 5a, discovery and storefront (QC-008, QC-043, QC-057, QC-060, QC-064):
 * - `search_query_stats`: searches counted per normalised query per day
 *   (no user, no IP) for popular / trending searches and suggestions;
 * - `recently_viewed_products`: a signed-in customer's recently viewed
 *   products, synced from the devices;
 * - `products.model_number` for the PDP;
 * - `coupons.is_public`: only coupons an admin marks public appear in
 *   "Offers for you" and the PDP coupon chips;
 * - travel: package gallery images (the cover stays the first image).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_query_stats', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('query', 100);
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamps();

            $table->unique(['day', 'query']);
            $table->index('query');
        });

        Schema::create('recently_viewed_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_user_id');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');

            $table->unique(['customer_user_id', 'product_id']);
            $table->index(['customer_user_id', 'viewed_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('model_number', 64)->nullable()->after('title_ar');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('is_active');
        });

        Schema::table('travel_packages', function (Blueprint $table) {
            $table->json('gallery')->nullable()->after('cover_path');
        });
    }

    public function down(): void
    {
        Schema::table('travel_packages', fn (Blueprint $table) => $table->dropColumn('gallery'));
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn('is_public'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('model_number'));
        Schema::dropIfExists('recently_viewed_products');
        Schema::dropIfExists('search_query_stats');
    }
};
