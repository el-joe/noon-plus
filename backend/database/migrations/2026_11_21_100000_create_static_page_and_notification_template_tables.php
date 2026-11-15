<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central content (WP7, SRS §15, ADR 10): static pages are country-specific
 * with a global fallback (country_id null); notification templates are per
 * country × event × channel × language, the global row (country_id null)
 * serving countries without their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('audience', 12)->default('both');
            $table->string('title_en');
            $table->string('title_ar');
            $table->longText('body_en');
            $table->longText('body_ar');
            $table->boolean('is_published')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['country_id', 'slug']);
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('channel', 16);
            $table->string('locale', 5);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['country_id', 'event', 'channel', 'locale'], 'notification_templates_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('static_pages');
    }
};
