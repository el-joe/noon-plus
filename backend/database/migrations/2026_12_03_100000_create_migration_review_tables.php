<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP11 extras — Admin → Data migration: the review decision on a
 * quarantine entry (resolved / waived, note, actor), the scope of a
 * single-row re-run in `migration_runs`, and `migration_jobs` (re-try
 * previews and runs, reconcile and redirect rebuilds started from the panel).
 * `resolved_at` stays owned by the migration itself (auto-resolve on a
 * successful re-run); a waiver never hides a row from reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_quarantine', function (Blueprint $table) {
            $table->string('review_status', 16)->nullable()->after('resolved_at');
            $table->string('review_note', 1000)->nullable()->after('review_status');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_note');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->index(['review_status', 'resolved_at']);
            $table->index('country_code');
        });

        Schema::table('migration_runs', function (Blueprint $table) {
            $table->string('scope', 160)->nullable()->after('countries');
        });

        Schema::create('migration_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('step', 32)->nullable();
            $table->string('country_code', 3)->nullable();
            $table->string('source_table', 64)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->unsignedBigInteger('quarantine_id')->nullable()->index();
            $table->boolean('dry_run')->default(false);
            $table->unsignedBigInteger('preview_id')->nullable();
            $table->string('status', 16)->default('queued');
            $table->uuid('run_id')->nullable();
            $table->json('metrics')->nullable();
            $table->text('output')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_jobs');

        Schema::table('migration_runs', function (Blueprint $table) {
            $table->dropColumn('scope');
        });

        Schema::table('migration_quarantine', function (Blueprint $table) {
            $table->dropIndex(['review_status', 'resolved_at']);
            $table->dropIndex(['country_code']);
            $table->dropColumn(['review_status', 'review_note', 'reviewed_by', 'reviewed_at']);
        });
    }
};
