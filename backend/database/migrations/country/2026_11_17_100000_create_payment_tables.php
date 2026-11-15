<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments (ADR 6, FR-CHK-3) — country database. One row per payment
 * attempt of an order with its status history, refunds and the gateway
 * webhook events (unique per gateway event id, so a redelivery is a no-op).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->after('payment_status');
            $table->index(['payment_status', 'payment_due_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('method', 24);
            $table->string('mode', 16)->nullable();
            $table->string('status', 24)->default('pending');
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->decimal('captured_amount', 19, 4)->default(0);
            $table->decimal('refunded_amount', 19, 4)->default(0);
            $table->string('idempotency_key', 100)->unique();
            $table->string('gateway_reference', 191)->nullable();
            $table->text('redirect_url')->nullable();
            $table->json('client_data')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['method', 'gateway_reference']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('payment_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('source', 32)->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->decimal('amount', 19, 4);
            $table->string('status', 16)->default('pending');
            $table->string('gateway_reference', 191)->nullable();
            $table->string('reason')->nullable();
            $table->string('failure_message')->nullable();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 24);
            $table->string('event_id', 191);
            $table->string('type', 100)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('received');
            $table->json('payload');
            $table->string('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_status_history');
        Schema::dropIfExists('payments');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_status', 'payment_due_at']);
            $table->dropColumn('payment_due_at');
        });
    }
};
