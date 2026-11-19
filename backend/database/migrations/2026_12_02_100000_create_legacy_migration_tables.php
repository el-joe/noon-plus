<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP11 data migration (docs/project/data-migration-plan.md §2–§3, §7):
 * - `migration_runs`: one row per `legacy:migrate` step run with its metrics;
 * - `migration_id_map`: legacy id → target row, per target database (an R4
 *   reference row has one target per country), with a checksum so a re-run
 *   of an unchanged row is a no-op;
 * - `migration_quarantine`: rows that could not be resolved (country, owner,
 *   parent…) — never silently dropped;
 * - `legacy_records`: rows archived as they were (rule R8 "archive", or no
 *   table in the new system yet); country rows go to the country database;
 * - `legacy_redirects`: the 301 map from old URLs, read by the website proxy.
 * Users and contract templates keep their legacy id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id')->index();
            $table->string('step', 32);
            $table->string('countries', 64)->nullable();
            $table->boolean('dry_run')->default(false);
            $table->json('metrics')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });

        Schema::create('migration_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 64);
            $table->string('source_id', 64);
            $table->string('target_db', 32);
            $table->string('target_table', 64);
            $table->unsignedBigInteger('target_id');
            $table->string('origin', 8)->default('inserted');
            $table->char('checksum', 40);
            $table->timestamp('migrated_at');
            $table->unique(['source_table', 'source_id', 'target_db', 'target_table'], 'migration_id_map_source_unique');
            $table->index(['target_db', 'target_table', 'target_id'], 'migration_id_map_target_index');
        });

        Schema::create('migration_quarantine', function (Blueprint $table) {
            $table->id();
            $table->string('step', 32);
            $table->string('source_table', 64);
            $table->string('source_id', 64);
            $table->string('country_code', 3)->nullable();
            $table->string('reason', 48);
            $table->string('detail', 500)->nullable();
            $table->json('payload')->nullable();
            $table->uuid('last_run_id');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['source_table', 'source_id'], 'migration_quarantine_source_unique');
            $table->index(['step', 'reason']);
        });

        Schema::create('legacy_records', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 64);
            $table->string('source_id', 64);
            $table->string('rule', 8);
            $table->string('reason', 48);
            $table->json('payload');
            $table->char('checksum', 40);
            $table->timestamp('archived_at');
            $table->unique(['source_table', 'source_id']);
        });

        Schema::create('legacy_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 255)->unique();
            $table->string('to_path', 255);
            $table->unsignedSmallInteger('status')->default(301);
            $table->string('source', 32);
            $table->string('country_code', 3)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->char('legacy_id', 36)->nullable()->unique()->after('id');
            $table->boolean('requires_email')->default(false)->after('email');
        });

        Schema::table('contract_templates', function (Blueprint $table) {
            $table->char('legacy_id', 36)->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('contract_templates', function (Blueprint $table) {
            $table->dropIndex(['legacy_id']);
            $table->dropColumn('legacy_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn(['legacy_id', 'requires_email']);
        });

        Schema::dropIfExists('legacy_redirects');
        Schema::dropIfExists('legacy_records');
        Schema::dropIfExists('migration_quarantine');
        Schema::dropIfExists('migration_id_map');
        Schema::dropIfExists('migration_runs');
    }
};
