<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA-021: emailed codes are scoped by purpose (customer sign-in, email
 * change, panel two-step), so one flow's code never works in, or replaces,
 * another's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_login_codes', function (Blueprint $table) {
            $table->string('purpose', 24)->default('login')->after('email');
            $table->index(['email', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('email_login_codes', function (Blueprint $table) {
            $table->dropIndex(['email', 'purpose']);
            $table->dropColumn('purpose');
        });
    }
};
