<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Category page rail (design category-page-target): "Grade" of a renewed /
 * used offer (A, B, C) and "Price drop" (the offer's price was lowered on
 * `price_dropped_at`, from `previous_price`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('grade', 8)->nullable()->after('condition');
            $table->decimal('previous_price', 19, 4)->nullable()->after('sale_price');
            $table->timestamp('price_dropped_at')->nullable()->after('previous_price');
            $table->index(['status', 'product_variant_id', 'vendor_id'], 'listings_status_variant_vendor_index');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('listings_status_variant_vendor_index');
            $table->dropColumn(['grade', 'previous_price', 'price_dropped_at']);
        });
    }
};
