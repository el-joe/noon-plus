<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery & logistics (WP7, SRS §4.1.11, §4.6, §8; lifecycle catalogue
     * §2.3, §2.4, §6.4, Appendix A "Delivery agent"; ADR 15). History rows go
     * to the shared `state_transitions` table.
     */
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->boolean('is_internal')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('delivery_agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('phone', 32)->nullable();
            $table->string('vehicle_type', 24)->nullable();
            $table->string('vehicle_plate', 24)->nullable();
            $table->json('zone_ids')->nullable();
            $table->string('status', 24)->default('pending_documents');
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'carrier_id']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('sub_order_id')->unique()->constrained();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('label_created');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->date('rescheduled_for')->nullable();
            $table->string('last_failure_reason', 48)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'carrier_id']);
        });

        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('shipment_id')->constrained();
            $table->foreignId('sub_order_id')->constrained();
            $table->foreignId('delivery_agent_id')->constrained();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('attempt_no')->default(1);
            $table->string('status', 16)->default('assigned');
            $table->string('delivery_code', 8);
            $table->string('assigned_via', 8)->default('manual');
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->char('currency', 3);
            $table->decimal('cod_amount', 19, 4)->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('sla_breached_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 48)->nullable();
            $table->string('failure_note', 255)->nullable();
            $table->string('decline_reason', 255)->nullable();
            $table->string('proof_photo_path')->nullable();
            $table->string('signature_path')->nullable();
            $table->timestamps();

            $table->index(['delivery_agent_id', 'status']);
            $table->index(['status', 'sla_due_at']);
            $table->index(['shipment_id', 'status']);
        });

        Schema::create('carrier_claims', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('carrier_id')->constrained();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sub_order_id')->constrained();
            $table->string('type', 16);
            $table->string('description', 1000);
            $table->char('currency', 3);
            $table->decimal('claimed_amount', 19, 4);
            $table->decimal('approved_amount', 19, 4)->nullable();
            $table->string('compensate_to', 16)->default('vendor');
            $table->string('status', 16)->default('submitted');
            $table->string('raised_by_type', 16);
            $table->unsignedBigInteger('raised_by_user_id')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamp('compensated_at')->nullable();
            $table->timestamps();

            $table->index(['carrier_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_claims');
        Schema::dropIfExists('delivery_assignments');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('delivery_agents');
        Schema::dropIfExists('carriers');
    }
};
