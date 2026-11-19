<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QC-040 (SRS §14 "comment and like"): live comments, moderated (blocked
 * words / links are held) and hideable by an admin, and viewer presence for
 * the live viewer count, peak and unique viewers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_stream_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('author_name', 80);
            $table->string('body', 300);
            $table->string('status', 12)->default('visible');
            $table->string('moderation_reason')->nullable();
            $table->unsignedBigInteger('moderated_by')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            $table->index(['live_stream_id', 'status', 'id']);
        });

        Schema::create('live_stream_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->char('viewer_hash', 64);
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['live_stream_id', 'viewer_hash']);
            $table->index(['live_stream_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_stream_viewers');
        Schema::dropIfExists('live_stream_comments');
    }
};
