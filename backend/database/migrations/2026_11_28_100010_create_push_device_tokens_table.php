<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-A2: push device tokens (FCM / APNs registration ids) stored per signed-in
 * device. Nothing sends push yet (no FCM package); the sender comes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            $table->string('token', 512);
            $table->char('token_hash', 64)->unique();
            $table->string('platform', 12);
            $table->string('app', 24)->default('customer');
            $table->string('country_code', 8)->nullable();
            $table->string('locale', 5)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_device_tokens');
    }
};
