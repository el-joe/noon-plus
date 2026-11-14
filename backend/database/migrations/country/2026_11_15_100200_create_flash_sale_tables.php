<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flash sales (SRS §10, lifecycle catalogue §4.10–4.11) — country database.
 * Times are stored in UTC; admins enter them in the country's local time.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flash_sale_events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name', 64)->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamp('submission_starts_at')->nullable();
            $table->timestamp('submission_ends_at')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('max_qty_per_customer');
            $table->string('marketer_bonus_type', 8)->nullable();
            $table->decimal('marketer_bonus_value', 19, 4)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('flash_sale_event_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_event_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('flash_sale_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained();
            $table->foreignId('vendor_id')->constrained();
            $table->string('status', 24)->default('draft');
            $table->decimal('original_price', 19, 4);
            $table->decimal('flash_price', 19, 4);
            $table->unsignedInteger('quantity_cap');
            $table->unsignedInteger('sold_quantity')->default(0);
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['flash_sale_event_id', 'listing_id']);
            $table->index(['listing_id', 'status']);
        });

        Schema::create('flash_sale_submission_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_submission_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flash_sale_submission_status_history');
        Schema::dropIfExists('flash_sale_submissions');
        Schema::dropIfExists('flash_sale_event_status_history');
        Schema::dropIfExists('flash_sale_events');
    }
};
