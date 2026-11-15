<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA wave 1 (country database): partner applications prove their email
 * with a code before submission (QA-002); team invitations for carrier
 * drivers / supervisors and partner staff that the person accepts by link
 * (QA-003, QC-020).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('email_code_hash')->nullable()->after('email_verified_at');
            $table->timestamp('email_code_expires_at')->nullable()->after('email_code_hash');
            $table->unsignedTinyInteger('email_code_attempts')->default(0)->after('email_code_expires_at');
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_type', 32);
            $table->unsignedBigInteger('tenant_id');
            $table->string('role', 40);
            $table->string('tenant_role', 40)->nullable();
            $table->string('email');
            $table->string('name', 120);
            $table->json('details')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('invited_by_user_id');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_type', 'tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');

        Schema::table('partner_applications', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'email_code_hash', 'email_code_expires_at', 'email_code_attempts']);
        });
    }
};
