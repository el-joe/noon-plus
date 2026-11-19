<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-11-19: a seller's checkout group holding both FBN
 * and seller-fulfilled items is split into two parcels. The warehouse part
 * is its own sub-order (`fulfilled_by` = `nawy`) pointing at the seller's
 * part through `split_from_sub_order_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->string('fulfilled_by', 16)->default('seller')->after('vendor_id');
            $table->unsignedBigInteger('split_from_sub_order_id')->nullable()->after('fulfilled_by')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->dropIndex(['split_from_sub_order_id']);
            $table->dropColumn(['fulfilled_by', 'split_from_sub_order_id']);
        });
    }
};
