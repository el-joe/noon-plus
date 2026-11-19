<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QC-007 support tickets (SRS §15, lifecycle catalogue §9.3): one ticket per
 * request from a customer or a business partner (store, travel agency,
 * marketer, shipping company), its thread with internal notes and private
 * attachments, the SLA due times and the requester's satisfaction rating.
 * Status history goes to `state_transitions` (subject `support_ticket`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->string('requester_type', 16);
            $table->unsignedBigInteger('requester_user_id');
            $table->string('tenant_type', 16)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->string('category', 24);
            $table->string('subject', 160);
            $table->string('priority', 8)->default('normal');
            $table->string('status', 24)->default('open');
            $table->unsignedBigInteger('assigned_admin_id')->nullable()->index();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sub_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 5)->default('en');
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('rating_comment', 1000)->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->timestamps();

            $table->index(['requester_type', 'requester_user_id']);
            $table->index(['tenant_type', 'tenant_id']);
            $table->index(['status', 'priority']);
            $table->index(['status', 'resolved_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 16);
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->json('attachments')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
