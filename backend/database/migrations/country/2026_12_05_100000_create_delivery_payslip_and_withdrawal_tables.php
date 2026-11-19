<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery agent money (WP7 follow-up): payslips per pay period (each
 * finished assignment is on exactly one payslip; issuing one credits the
 * agent wallet in the ledger) and wallet withdrawals to a bank account
 * (pending → approved → paid, four-eyes). Classified categories get their
 * own photo size limit (`photo_max_kb`, inherited down the tree).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_payslips', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('delivery_agent_id')->constrained();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->decimal('per_stop', 19, 4)->nullable();
            $table->decimal('per_failed', 19, 4)->nullable();
            $table->decimal('total', 19, 4);
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->index(['delivery_agent_id', 'period_start']);
        });

        Schema::create('delivery_payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_payslip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_assignment_id')->unique()->constrained();
            $table->string('outcome', 12);
            $table->timestamp('finished_at');
            $table->decimal('amount', 19, 4);
        });

        Schema::create('delivery_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('delivery_agent_id')->constrained();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->string('status', 12)->default('pending');
            $table->text('payout_details');
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestamps();

            $table->index(['status', 'carrier_id']);
            $table->index(['delivery_agent_id', 'status']);
        });

        Schema::table('classified_categories', function (Blueprint $table) {
            $table->unsignedInteger('photo_max_kb')->nullable()->after('max_photos');
        });
    }

    public function down(): void
    {
        Schema::table('classified_categories', function (Blueprint $table) {
            $table->dropColumn('photo_max_kb');
        });
        Schema::dropIfExists('delivery_withdrawals');
        Schema::dropIfExists('delivery_payslip_lines');
        Schema::dropIfExists('delivery_payslips');
    }
};
