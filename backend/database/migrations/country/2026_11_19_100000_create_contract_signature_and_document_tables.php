<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract signatures with evidence (SRS §13.6) and uploaded documents /
 * product certifications (SRS §13.7) — country database. A signature keeps
 * the typed name, IP, user agent, method, locale, a frozen copy on the
 * private disk and its SHA-256; it is never edited or deleted (status only
 * moves to superseded / revoked). Products get the individual-contract
 * switch (required / exempt / inherit) and categories the campaign
 * minimum stock and sample quantities (SRS §20.2).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contract_signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_template_id');
            $table->unsignedBigInteger('contract_version_id');
            $table->unsignedInteger('version');
            $table->string('kind', 32);
            $table->string('signer_type', 16);
            $table->unsignedBigInteger('signer_id');
            $table->unsignedBigInteger('signer_user_id')->nullable();
            $table->string('subject_type', 16)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('purpose', 16)->nullable();
            $table->string('full_name', 160);
            $table->string('locale', 5);
            $table->string('method', 16);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('frozen_path');
            $table->char('sha256', 64);
            $table->string('status', 12)->default('active');
            $table->boolean('open_call')->default(false);
            $table->timestamp('signed_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['signer_type', 'signer_id', 'kind', 'status'], 'contract_signatures_signer_index');
            $table->index(['contract_version_id']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_type_id');
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            $table->string('status', 12)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('contract_requirement', 8)->default('inherit')->after('status');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('min_stock_for_campaign')->nullable()->after('requires_certification');
            $table->unsignedInteger('platform_sample_qty')->nullable()->after('min_stock_for_campaign');
            $table->unsignedInteger('influencer_sample_qty')->nullable()->after('platform_sample_qty');
            $table->unsignedInteger('affiliate_sample_qty')->nullable()->after('influencer_sample_qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['min_stock_for_campaign', 'platform_sample_qty', 'influencer_sample_qty', 'affiliate_sample_qty']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('contract_requirement');
        });
        Schema::dropIfExists('documents');
        Schema::dropIfExists('contract_signatures');
    }
};
