<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketers & campaigns (SRS §11, MP §8) — country database: marketing
 * agencies, marketer profiles (job types, global status), commission rules
 * (per marketer or platform default, per vertical / category), campaign fee
 * rules, campaigns (origins A–F), invitations, referral clicks, conversions
 * (idempotent per source × invitation, with clawback), samples, content
 * submissions, marketer wallet withdrawals and the cross-promotion policy
 * engine. Every lifecycle writes `state_transitions`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketing_agencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 12)->default('pending');
            $table->timestamps();
        });

        Schema::create('marketer_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->foreignId('agency_id')->nullable()->constrained('marketing_agencies')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('display_name');
            $table->text('bio')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->json('job_types');
            $table->string('global_status', 12)->default('pending');
            $table->unsignedInteger('followers')->default(0);
            $table->boolean('is_verified')->default(false);
            $table->string('whatsapp_phone', 32)->nullable();
            $table->json('socials')->nullable();
            $table->json('category_ids')->nullable();
            $table->string('status_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('global_status');
        });

        Schema::create('marketer_commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketer_profile_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('vertical', 16)->default('product');
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('influencer_fixed', 19, 4)->nullable();
            $table->decimal('affiliate_percent', 7, 4)->nullable();
            $table->decimal('broker_fixed', 19, 4)->nullable();
            $table->decimal('broker_percent', 7, 4)->nullable();
            $table->decimal('fee_percent', 7, 4)->nullable();
            $table->decimal('fee_fixed', 19, 4)->nullable();
            $table->timestamps();

            $table->index(['marketer_profile_id', 'vertical', 'category_id'], 'marketer_rules_lookup');
        });

        Schema::create('campaign_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12);
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('fulfilment', 16)->nullable();
            $table->string('vertical', 16)->nullable();
            $table->decimal('amount', 19, 4)->default(0);
            $table->decimal('percent', 7, 4)->default(0);
            $table->timestamps();

            $table->index(['kind', 'category_id', 'fulfilment']);
        });

        Schema::create('marketer_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('origin', 16);
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_marketer_id')->nullable()->constrained('marketer_profiles')->nullOnDelete();
            $table->foreignId('listing_id')->constrained();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->json('ad_types')->nullable();
            $table->json('planned_marketers')->nullable();
            $table->boolean('open_to_affiliates')->default(false);
            $table->string('attribution_model', 16)->nullable();
            $table->char('currency', 3);
            $table->decimal('budget', 19, 4)->nullable();
            $table->decimal('budget_spent', 19, 4)->default(0);
            $table->decimal('influencer_fixed_reserved', 19, 4)->default(0);
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 24);
            $table->timestamp('owner_paused_at')->nullable();
            $table->timestamp('auto_approve_at')->nullable();
            $table->timestamp('live_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('influencers_settled_at')->nullable();
            $table->decimal('fee_amount', 19, 4)->default(0);
            $table->json('fee_snapshot')->nullable();
            $table->string('fee_status', 12)->default('none');
            $table->timestamp('fee_charged_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'status']);
            $table->index(['status', 'auto_approve_at']);
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('campaign_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketer_campaigns')->cascadeOnDelete();
            $table->foreignId('marketer_profile_id')->constrained();
            $table->string('effective_type', 16);
            $table->string('source', 16);
            $table->string('status', 16)->default('pending');
            $table->string('referral_code', 16)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->decimal('influencer_fixed', 19, 4)->nullable();
            $table->string('influencer_fixed_status', 12)->default('none');
            $table->json('rate_snapshot')->nullable();
            $table->foreignId('replaced_invitation_id')->nullable()->constrained('campaign_invitations')->nullOnDelete();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('response_note')->nullable();
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['marketer_profile_id', 'status']);
        });

        Schema::create('referral_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained('campaign_invitations')->cascadeOnDelete();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('marketer_profile_id');
            $table->unsignedBigInteger('listing_id');
            $table->string('visitor_token', 64)->nullable();
            $table->unsignedBigInteger('customer_user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('clicked_at');
            $table->timestamp('expires_at');

            $table->index(['customer_user_id', 'listing_id', 'clicked_at']);
            $table->index(['visitor_token', 'listing_id', 'clicked_at']);
        });

        Schema::create('marketer_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketer_campaigns');
            $table->foreignId('invitation_id')->constrained('campaign_invitations');
            $table->foreignId('marketer_profile_id')->constrained();
            $table->string('type', 24)->default('product_sale');
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('sub_order_id')->nullable();
            $table->string('attribution', 16);
            $table->decimal('weight', 7, 4)->default(1);
            $table->char('currency', 3);
            $table->decimal('basis', 19, 4);
            $table->decimal('gross', 19, 4);
            $table->decimal('fee', 19, 4);
            $table->decimal('net', 19, 4);
            $table->decimal('bonus', 19, 4)->default(0);
            $table->decimal('reversed_gross', 19, 4)->default(0);
            $table->string('status', 24)->default('pending');
            $table->decimal('clawback_amount', 19, 4)->default(0);
            $table->decimal('recovered_amount', 19, 4)->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'invitation_id'], 'marketer_conversions_source_unique');
            $table->index(['status', 'created_at']);
            $table->index(['marketer_profile_id', 'status']);
        });

        Schema::create('campaign_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketer_campaigns')->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('campaign_invitations')->nullOnDelete();
            $table->foreignId('marketer_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12);
            $table->unsignedInteger('quantity');
            $table->string('status', 16)->default('planned');
            $table->json('address')->nullable();
            $table->string('tracking_number', 64)->nullable();
            $table->decimal('commission_amount', 19, 4)->default(0);
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('review_due_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('content_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketer_campaigns')->cascadeOnDelete();
            $table->foreignId('invitation_id')->constrained('campaign_invitations')->cascadeOnDelete();
            $table->foreignId('marketer_profile_id')->constrained();
            $table->string('platform', 24);
            $table->string('url', 500);
            $table->text('caption')->nullable();
            $table->string('status', 12)->default('submitted');
            $table->string('rejection_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('marketer_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('marketer_profile_id')->constrained();
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->string('status', 12)->default('pending');
            $table->json('payout_details')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cross_promo_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 24);
            $table->boolean('is_active')->default(true);
            $table->json('eligibility')->nullable();
            $table->json('obligation')->nullable();
            $table->json('allocation')->nullable();
            $table->json('penalty')->nullable();
            $table->json('exemptions')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
        });

        Schema::create('cross_promo_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('cross_promo_policies')->cascadeOnDelete();
            $table->foreignId('marketer_profile_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->unsignedInteger('required');
            $table->unsignedInteger('completed')->default(0);
            $table->string('status', 12);
            $table->json('penalty')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'marketer_profile_id', 'period'], 'cross_promo_participation_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['cross_promo_participations', 'cross_promo_policies', 'marketer_withdrawals', 'content_submissions', 'campaign_samples', 'marketer_conversions', 'referral_clicks', 'campaign_invitations', 'marketer_campaigns', 'campaign_fee_rules', 'marketer_commission_rules', 'marketer_profiles', 'marketing_agencies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
