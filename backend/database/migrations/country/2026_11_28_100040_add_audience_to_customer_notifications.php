<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QC-041: the notification table also feeds the bell in the business
 * panels. `audience` is `customer` (storefront / app) or `panel:{key}`, so a
 * person who is both a customer and a seller sees each list in its place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->string('audience', 24)->default('customer')->after('user_id');
            $table->index(['user_id', 'audience', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'audience', 'read_at']);
            $table->dropColumn('audience');
        });
    }
};
