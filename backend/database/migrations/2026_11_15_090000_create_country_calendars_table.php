<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public holidays per country (MASTER_PLAN §12 "country reference", settings
 * catalogue `country.holidays`). Central, next to `countries.weekend`: it is
 * country reference data that Ops edits for all 8 countries from one admin
 * screen, and the ETA, SLA and payout calculators read it together with the
 * weekend without switching country databases. Dates are country-local.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('country_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->boolean('is_confirmed')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['country_id', 'starts_on', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('country_calendars');
    }
};
