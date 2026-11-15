<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract engine and document types (ADR 8, SRS §13, MP §9) — central
 * database. Templates carry a country column (null = legacy/global, which
 * serves countries without their own); resolution never falls back to
 * another country. Versions are never edited once published. Assignments
 * link a template lineage to a country subject (category, product, marketer,
 * job type). Document types are per country × business type × category.
 * `referral_codes` keeps invitation codes globally unique so `/r/{code}`
 * resolves without a market.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('name');
            $table->string('mode', 8)->default('editor');
            $table->string('consent_mode', 12)->default('click_wrap');
            $table->boolean('is_default')->default(false);
            $table->boolean('required_for_customers')->default(false);
            $table->boolean('open_call')->default(false);
            $table->json('destinations')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['kind', 'country_id']);
        });

        Schema::create('contract_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 12)->default('draft');
            $table->longText('body_en')->nullable();
            $table->longText('body_ar')->nullable();
            $table->string('file_path_ar')->nullable();
            $table->string('file_path_en')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->string('change_summary')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamps();

            $table->unique(['contract_template_id', 'version']);
            $table->index(['status', 'effective_at']);
        });

        Schema::create('contract_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 16);
            $table->string('subject_key', 64);
            $table->timestamps();

            $table->unique(['contract_template_id', 'country_id', 'subject_type', 'subject_key'], 'contract_assignments_unique');
            $table->index(['country_id', 'subject_type', 'subject_key']);
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('business_type', 16);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('requirement', 16)->default('mandatory');
            $table->boolean('has_expiry')->default(false);
            $table->unsignedSmallInteger('max_mb')->default(10);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['country_id', 'code', 'business_type', 'category_id'], 'document_types_unique');
        });

        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('invitation_id');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('contract_assignments');
        Schema::dropIfExists('contract_versions');
        Schema::dropIfExists('contract_templates');
    }
};
