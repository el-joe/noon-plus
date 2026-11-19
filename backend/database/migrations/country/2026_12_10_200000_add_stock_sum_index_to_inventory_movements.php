<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Category page performance: the stock of an offer is the sum of its
 * movements; (listing_id, quantity) lets that sum (and the per-request list
 * of offers without stock) be read from the index alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(['listing_id', 'quantity'], 'inventory_movements_listing_quantity_index');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('inventory_movements_listing_quantity_index');
        });
    }
};
