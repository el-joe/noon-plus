<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('preferred_locale', 5)->default('en')->after('phone');
            $table->string('status', 16)->default('active')->after('preferred_locale');
            $table->foreignId('home_country_id')->nullable()->after('status')->constrained('countries')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('home_country_id');
            $table->dropColumn(['phone', 'preferred_locale', 'status', 'last_login_at']);
        });
    }
};
