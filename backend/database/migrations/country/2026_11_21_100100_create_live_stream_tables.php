<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live streams and scheduled audio/video channels (WP7, SRS §14, ADR 11
     * admin-only; native WebRTC since D18). History rows go to `state_transitions`.
     */
    public function up(): void
    {
        Schema::create('live_streams', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('source', 12);
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('media_mode', 8)->default('video');
            $table->string('status', 12);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('stream_key', 64)->nullable();
            $table->string('room_name', 96)->nullable();
            $table->string('provider_room_id', 96)->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime', 64)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('playback_mode', 8)->default('synced');
            $table->unsignedInteger('peak_viewers')->default(0);
            $table->unsignedInteger('total_viewers')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['source', 'status']);
        });

        Schema::create('live_stream_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['live_stream_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_stream_products');
        Schema::dropIfExists('live_streams');
    }
};
