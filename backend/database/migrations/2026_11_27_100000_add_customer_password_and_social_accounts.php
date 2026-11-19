<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D9 (QC-005): customers may add an optional password and sign in with
 * Google or Apple. `password_set_at` marks a password the person chose
 * (accounts created by email code or social sign-in keep a random one);
 * social identities link to the account by provider subject; login history
 * records the sign-in method.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_set_at')->nullable()->after('password');
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('provider_user_id', 191);
            $table->string('email')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::table('login_events', function (Blueprint $table) {
            $table->string('method', 16)->nullable()->after('channel');
        });

        // Panel accounts already sign in with a password they chose.
        DB::table('users')
            ->whereNull('password_set_at')
            ->whereIn('id', DB::table('role_memberships')->where('role', '!=', 'customer')->select('user_id'))
            ->update(['password_set_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('login_events', fn (Blueprint $table) => $table->dropColumn('method'));
        Schema::dropIfExists('social_accounts');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('password_set_at'));
    }
};
