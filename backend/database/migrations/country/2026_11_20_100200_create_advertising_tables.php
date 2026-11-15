<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advertising (WP6, SRS §10, §11.13, lifecycle catalogue §4.5–4.9) —
 * country database: self-serve ad campaigns (CPC / CPM, daily + total
 * budgets, targeting) with their billable events and daily spend, ad slots
 * and date bookings, ad packages with subscriptions, and coupon
 * participation invitations with requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->string('advertiser_type', 12);
            $table->unsignedBigInteger('advertiser_id');
            $table->foreignId('listing_id')->constrained();
            $table->string('name');
            $table->string('pricing_model', 4);
            $table->decimal('bid', 19, 4);
            $table->char('currency', 3);
            $table->decimal('daily_budget', 19, 4);
            $table->decimal('total_budget', 19, 4)->nullable();
            $table->decimal('spent_today', 19, 4)->default(0);
            $table->decimal('spent_total', 19, 4)->default(0);
            $table->decimal('unbilled_spend', 19, 4)->default(0);
            $table->json('keywords')->nullable();
            $table->json('category_ids')->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('total_exhausted')->default(false);
            $table->string('rejection_reason')->nullable();
            $table->date('spend_date')->nullable();
            $table->timestamps();

            $table->index(['status', 'listing_id']);
            $table->index(['advertiser_type', 'advertiser_id']);
        });

        Schema::create('ad_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);
            $table->string('placement', 24);
            $table->string('visitor_key', 64)->nullable();
            $table->decimal('cost', 19, 4)->default(0);
            $table->boolean('is_billable')->default(true);
            $table->string('invalid_reason', 32)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ad_campaign_id', 'type', 'visitor_key', 'created_at'], 'ad_events_dedupe_index');
        });

        Schema::create('ad_slots', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name');
            $table->string('placement', 24);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('dimensions', 24)->nullable();
            $table->decimal('price_per_day', 19, 4);
            $table->unsignedTinyInteger('max_concurrent')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ad_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->foreignId('ad_slot_id')->constrained();
            $table->string('advertiser_type', 12);
            $table->unsignedBigInteger('advertiser_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('days');
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->string('creative_path')->nullable();
            $table->string('creative_type', 8)->default('image');
            $table->string('link_url')->nullable();
            $table->string('headline')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('payment_status', 12)->default('unpaid');
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['ad_slot_id', 'status', 'starts_on', 'ends_on']);
        });

        Schema::create('ad_packages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('target_type', 12)->default('marketer');
            $table->decimal('price', 19, 4);
            $table->unsignedSmallInteger('duration_days');
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ad_package_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_package_id')->constrained();
            $table->string('subscriber_type', 12);
            $table->unsignedBigInteger('subscriber_id');
            $table->string('payment_method', 16);
            $table->string('proof_path')->nullable();
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->decimal('vat_amount', 19, 4)->default(0);
            $table->string('status', 12);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('coupon_participation_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained();
            $table->string('title');
            $table->char('currency', 3);
            $table->decimal('min_fee', 19, 4);
            $table->unsignedInteger('capacity');
            $table->json('audience')->nullable();
            $table->timestamp('closes_at');
            $table->string('status', 12)->default('draft');
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'closes_at']);
        });

        Schema::create('coupon_participation_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_participation_invitation_id');
            $table->string('participant_type', 12);
            $table->unsignedBigInteger('participant_id');
            $table->decimal('offered_fee', 19, 4);
            $table->string('payment_method', 16)->default('wallet');
            $table->string('proof_path')->nullable();
            $table->string('status', 28)->default('requested');
            $table->string('decision_note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['coupon_participation_invitation_id', 'participant_type', 'participant_id'], 'coupon_participation_unique');
            $table->foreign('coupon_participation_invitation_id', 'coupon_participation_invitation_fk')->references('id')->on('coupon_participation_invitations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['coupon_participation_requests', 'coupon_participation_invitations', 'ad_package_subscriptions', 'ad_packages', 'ad_bookings', 'ad_slots', 'ad_events', 'ad_campaigns'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
