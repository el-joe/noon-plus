<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RT-006: a resume link adds an access token instead of replacing the one
 * the applicant's open wizard is using. The extra tokens are kept as
 * hashes (newest few only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->json('resume_token_hashes')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->dropColumn('resume_token_hashes');
        });
    }
};
