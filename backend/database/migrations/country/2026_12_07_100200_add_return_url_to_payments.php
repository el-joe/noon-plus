<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app payments: where the gateway return lands when the payment was
 * started from an app (a deep link from `nawy.payments.app_return_urls`,
 * e.g. `nawytravel://promotions/return`); null = the web default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('return_url', 255)->nullable()->after('redirect_url');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('return_url'));
    }
};
