<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-FIN-6: who brought the partner in. `acquired_by_user_id` is the
 * account manager (central user) credited for the store — set from an
 * account manager's referral link (`?ref=` → `referral_code`) or by the
 * admin reviewing the application; approval copies it to
 * `vendors.acquired_by_user_id` / `acquired_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('acquired_by_user_id')->nullable()->after('assigned_to_user_id')->index();
            $table->string('referral_code', 40)->nullable()->after('acquired_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->dropIndex(['acquired_by_user_id']);
            $table->dropColumn(['acquired_by_user_id', 'referral_code']);
        });
    }
};
