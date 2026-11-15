<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QC-020: staff role inside a tenant (partner store, travel agency,
 * shipping company). Null = the panel's default staff role; the tenant
 * owner always has every right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_memberships', function (Blueprint $table) {
            $table->string('tenant_role', 40)->nullable()->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('role_memberships', function (Blueprint $table) {
            $table->dropColumn('tenant_role');
        });
    }
};
