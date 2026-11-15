<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront API gaps round 2 (G-W25, G-W29) — central database: the
 * customer's avatar and marketing preferences, and SEO title/description
 * of static pages.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('preferred_locale');
            $table->json('marketing_preferences')->nullable()->after('avatar_path');
        });

        Schema::table('static_pages', function (Blueprint $table) {
            $table->string('seo_title_en', 160)->nullable()->after('body_ar');
            $table->string('seo_title_ar', 160)->nullable()->after('seo_title_en');
            $table->string('seo_description_en', 320)->nullable()->after('seo_title_ar');
            $table->string('seo_description_ar', 320)->nullable()->after('seo_description_en');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('static_pages', fn (Blueprint $table) => $table->dropColumn(['seo_title_en', 'seo_title_ar', 'seo_description_en', 'seo_description_ar']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['avatar_path', 'marketing_preferences']));
    }
};
