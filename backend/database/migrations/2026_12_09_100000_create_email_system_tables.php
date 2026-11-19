<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email system (docs/project/email-catalogue.md): the delivery log of every
 * catalogue email, the suppression list (hard bounces, complaints,
 * unsubscribes of guests), per-user email preferences and the version
 * history of the editable notification templates. All central.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_log', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64)->index();
            $table->string('category', 24);
            $table->string('actor', 16);
            $table->foreignId('user_id')->nullable()->index();
            $table->char('recipient_hash', 64)->index();
            $table->string('recipient_masked', 120);
            $table->text('recipient_encrypted');
            $table->string('locale', 5);
            $table->string('country_code', 8)->nullable();
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->unsignedInteger('template_version')->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 16)->default('queued')->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->longText('payload')->nullable();
            $table->foreignId('resent_from_id')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->char('email_hash', 64);
            $table->string('email_masked', 120);
            $table->string('topic', 64)->default('*');
            $table->string('reason', 24);
            $table->string('source', 32)->nullable();
            $table->text('detail')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['email_hash', 'topic']);
        });

        Schema::create('email_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 64);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'topic']);
        });

        Schema::table('notification_templates', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('is_active');
        });

        Schema::create('notification_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('channel', 16);
            $table->string('locale', 5);
            $table->unsignedInteger('version');
            $table->string('action', 12)->default('saved');
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['event', 'channel', 'locale', 'country_id'], 'notification_template_versions_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_template_versions');
        Schema::table('notification_templates', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::dropIfExists('email_preferences');
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('email_log');
    }
};
