<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->char('iso2', 2)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->json('weekend');
            $table->string('tax_name', 16)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('phone_code', 8);
            $table->json('locales');
            $table->string('default_locale', 5);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
