<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QC-028: the language a booking was made in, so the agency's later
 * confirm / reject emails reach the customer in that language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_bookings', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('travel_bookings', fn (Blueprint $table) => $table->dropColumn('locale'));
    }
};
