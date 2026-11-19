<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RT-002: a guest cart remembers a keyed hash of the IP that created it, so
 * the units held for one listing can be capped across every guest cart
 * from that IP (the raw address is never stored).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('guest_ip_hash', 64)->nullable()->after('guest_token')->index();
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropIndex(['guest_ip_hash']);
            $table->dropColumn('guest_ip_hash');
        });
    }
};
