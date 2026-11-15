<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money (WP4, MASTER_PLAN ADR 3–4, §10; SRS §7.4–§7.7, §8–§10) — country
 * database: the double-entry ledger (wallets are ledger accounts per owner ×
 * currency × bucket), promotional credits, refunds, returns, disputes,
 * warranty claims, vendor settlements and payouts, COD collections and
 * settlements, gift cards, vouchers and Nawy One membership. Every new state
 * machine writes `state_transitions` (subject, from, to, actor, reason).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('state_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('owner_type', 16)->default('platform');
            $table->unsignedBigInteger('owner_id')->default(0);
            $table->string('bucket', 24)->default('main');
            $table->char('currency', 3);
            $table->string('normal_side', 6);
            $table->decimal('balance', 19, 4)->default(0);
            $table->timestamps();

            $table->unique(['code', 'owner_type', 'owner_id', 'bucket', 'currency'], 'ledger_accounts_identity_unique');
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 150)->unique();
            $table->string('type', 40)->index();
            $table->char('currency', 3);
            $table->decimal('total', 19, 4);
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('posted_at')->useCurrent();

            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained();
            $table->foreignId('ledger_account_id')->constrained();
            $table->string('direction', 6);
            $table->decimal('amount', 19, 4);
            $table->decimal('balance_after', 19, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ledger_account_id', 'id']);
        });

        Schema::create('promotional_credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_user_id');
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->decimal('remaining', 19, 4);
            $table->string('status', 16)->default('granted');
            $table->string('source', 32);
            $table->string('reference', 100)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['customer_user_id', 'status', 'expires_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();
            $table->string('payable_type', 32)->nullable()->after('order_id');
            $table->unsignedBigInteger('payable_id')->nullable()->after('payable_type');
            $table->index(['payable_type', 'payable_id']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->string('payout_schedule', 16)->default('weekly')->after('seller_agreement_accepted_at');
        });

        Schema::table('sub_orders', function (Blueprint $table) {
            $table->boolean('cod_remittance_confirmed')->default(false)->after('delivered_at');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('return_window_days');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('sub_order_id')->nullable()->constrained();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->decimal('warranty_amount', 19, 4)->default(0);
            $table->string('destination', 16);
            $table->string('status', 16)->default('pending');
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('payment_refund_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('after_settlement')->default(false);
            $table->string('reason')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });

        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('sub_order_id')->constrained();
            $table->foreignId('vendor_id')->constrained();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('status', 24)->default('requested');
            $table->string('reason', 32);
            $table->text('comment')->nullable();
            $table->string('method', 16);
            $table->string('refund_destination', 16);
            $table->json('photos')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->boolean('carrier_investigation')->default(false);
            $table->decimal('refund_total', 19, 4)->nullable();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['sub_order_id', 'status']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->decimal('paid_amount', 19, 4);
            $table->decimal('warranty_amount', 19, 4)->default(0);
            $table->string('outcome', 24)->nullable();
            $table->boolean('restock')->default(false);
            $table->decimal('refund_amount', 19, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('sub_order_id')->constrained();
            $table->foreignId('vendor_id')->constrained();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('status', 24)->default('open');
            $table->string('reason', 32);
            $table->string('resolution', 24)->nullable();
            $table->decimal('refund_amount', 19, 4)->nullable();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['sub_order_id', 'status']);
        });

        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 16);
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('order_item_id')->constrained();
            $table->foreignId('sub_order_id')->constrained();
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('warranty_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('coverage', 16);
            $table->string('status', 24)->default('submitted');
            $table->text('description');
            $table->string('outcome', 16)->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });

        Schema::create('warranty_claim_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_claim_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 16);
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('sub_order_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_order_id')->unique()->constrained();
            $table->foreignId('vendor_id')->constrained();
            $table->char('currency', 3);
            $table->boolean('is_cod')->default(false);
            $table->decimal('gross', 19, 4);
            $table->decimal('vendor_discount', 19, 4)->default(0);
            $table->decimal('refunds', 19, 4)->default(0);
            $table->decimal('commission', 19, 4)->default(0);
            $table->decimal('commission_vat', 19, 4)->default(0);
            $table->decimal('fees', 19, 4)->default(0);
            $table->decimal('fbn_fees', 19, 4)->default(0);
            $table->decimal('subsidy_share', 19, 4)->default(0);
            $table->decimal('campaign_fees', 19, 4)->default(0);
            $table->decimal('sample_commissions', 19, 4)->default(0);
            $table->decimal('net', 19, 4);
            $table->json('lines');
            $table->timestamp('settled_at');
            $table->timestamps();

            $table->index(['vendor_id', 'settled_at']);
        });

        Schema::create('vendor_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('vendor_id')->constrained();
            $table->char('currency', 3);
            $table->string('status', 16)->default('pending');
            $table->string('schedule', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total', 19, 4);
            $table->unsignedBigInteger('generated_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->string('hold_reason')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('bank_reference', 100)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'period_end']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('vendor_balance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained();
            $table->string('type', 24);
            $table->string('reference_type', 32);
            $table->unsignedBigInteger('reference_id');
            $table->string('description');
            $table->decimal('amount', 19, 4);
            $table->boolean('is_eligible')->default(true);
            $table->foreignId('vendor_payout_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'reference_type', 'reference_id']);
            $table->index(['vendor_id', 'is_eligible', 'vendor_payout_id'], 'vendor_balance_items_open_index');
        });

        Schema::create('cod_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->unsignedBigInteger('agent_user_id')->nullable();
            $table->string('agent_key', 32);
            $table->date('business_date');
            $table->char('currency', 3);
            $table->decimal('expected_amount', 19, 4);
            $table->decimal('remitted_amount', 19, 4)->nullable();
            $table->unsignedInteger('collections_count');
            $table->string('status', 16)->default('pending');
            $table->string('note')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_key', 'business_date']);
        });

        Schema::create('cod_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_order_id')->unique()->constrained();
            $table->foreignId('order_id')->constrained();
            $table->unsignedBigInteger('agent_user_id')->nullable();
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->decimal('cod_fee', 19, 4)->default(0);
            $table->timestamp('collected_at');
            $table->foreignId('cod_settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['cod_settlement_id', 'collected_at']);
        });

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->char('code_hash', 64)->unique();
            $table->text('code_encrypted');
            $table->char('code_last4', 4);
            $table->string('status', 24)->default('pending_payment');
            $table->string('source', 16);
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->decimal('balance', 19, 4);
            $table->unsignedBigInteger('purchaser_user_id')->nullable()->index();
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_email');
            $table->string('message', 500)->nullable();
            $table->timestamp('send_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('redeemed_by_user_id')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'send_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->string('label', 120);
            $table->string('status', 16)->default('active');
            $table->char('currency', 3);
            $table->decimal('value', 19, 4);
            $table->unsignedInteger('max_redemptions')->default(1);
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->unsignedSmallInteger('credit_expiry_days');
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained();
            $table->unsignedBigInteger('customer_user_id');
            $table->foreignId('promotional_credit_id')->constrained();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['voucher_id', 'customer_user_id']);
        });

        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('interval', 16);
            $table->decimal('price', 19, 4);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('free_shipping')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_user_id')->unique();
            $table->foreignId('membership_plan_id')->constrained();
            $table->string('status', 16);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('past_due_since')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_period_end']);
        });

        Schema::create('membership_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained();
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->string('status', 16)->default('pending');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['membership_id', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'membership_invoices', 'memberships', 'membership_plans', 'voucher_redemptions', 'vouchers', 'gift_cards',
            'cod_collections', 'cod_settlements', 'vendor_balance_items', 'vendor_payouts', 'sub_order_settlements',
            'warranty_claim_messages', 'warranty_claims', 'dispute_messages', 'disputes', 'return_items', 'return_requests', 'refunds',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('commission_rate'));
        Schema::table('sub_orders', fn (Blueprint $table) => $table->dropColumn('cod_remittance_confirmed'));
        Schema::table('vendors', fn (Blueprint $table) => $table->dropColumn('payout_schedule'));
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payable_type', 'payable_id']);
            $table->dropColumn(['payable_type', 'payable_id']);
        });

        Schema::dropIfExists('promotional_credits');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('state_transitions');
    }
};
