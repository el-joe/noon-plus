<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Classifieds & real estate (WP6, SRS §12.1, §11.13, spec §5.7, MP §14b) —
 * country database: dynamic classified categories (sections, contract flag,
 * duration, renewal price) with attribute schemas, ads (offer / wanted,
 * lifecycle in `state_transitions`), media, attribute values for filtering,
 * property details (permits), paid packages (featured / boost / republish),
 * inquiry threads + messages, phone reveal log, saved searches, favourites,
 * abuse reports, follows, broker details, leads + follow-ups, viewings,
 * special requests and exclusive contracts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('rating');
        });

        Schema::create('classified_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('classified_categories')->nullOnDelete();
            $table->string('slug', 120)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('section', 32)->nullable();
            $table->string('vertical', 16)->default('general');
            $table->boolean('requires_contract')->default(false);
            $table->unsignedSmallInteger('listing_duration_days')->nullable();
            $table->decimal('renewal_price', 19, 4)->nullable();
            $table->unsignedTinyInteger('max_photos')->default(20);
            $table->boolean('allows_video')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('classified_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classified_category_id')->constrained()->cascadeOnDelete();
            $table->string('key', 48);
            $table->string('label_en');
            $table->string('label_ar');
            $table->string('type', 16);
            $table->json('options')->nullable();
            $table->string('unit', 16)->nullable();
            $table->decimal('min_value', 19, 4)->nullable();
            $table->decimal('max_value', 19, 4)->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['classified_category_id', 'key']);
        });

        Schema::create('classified_ads', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->foreignId('classified_category_id')->constrained();
            $table->unsignedBigInteger('owner_user_id')->index();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketer_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 8)->default('offer');
            $table->string('title');
            $table->text('description')->nullable();
            $table->char('currency', 3);
            $table->decimal('price', 19, 4)->nullable();
            $table->boolean('is_negotiable')->default(false);
            $table->string('city', 80)->nullable();
            $table->string('area', 80)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('contact_phone', 24)->nullable();
            $table->json('specs')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('rejection_reason')->nullable();
            $table->unsignedBigInteger('contract_signature_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('reminders_sent')->nullable();
            $table->timestamp('featured_until')->nullable();
            $table->timestamp('boosted_until')->nullable();
            $table->timestamp('bumped_at')->nullable();
            $table->decimal('sold_price', 19, 4)->nullable();
            $table->foreignId('sold_marketer_profile_id')->nullable()->constrained('marketer_profiles')->nullOnDelete();
            $table->string('sold_buyer_note')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->index(['status', 'classified_category_id']);
            $table->index(['status', 'expires_at']);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('classified_ad_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classified_ad_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);
            $table->string('path');
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('classified_ad_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classified_ad_id')->constrained()->cascadeOnDelete();
            $table->string('key', 48);
            $table->string('value_string')->nullable();
            $table->decimal('value_number', 19, 4)->nullable();

            $table->index(['key', 'value_string']);
            $table->index(['key', 'value_number']);
        });

        Schema::create('property_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classified_ad_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('purpose', 12);
            $table->string('property_type', 24);
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->string('furnishing', 16)->nullable();
            $table->string('rent_period', 12)->nullable();
            $table->string('permit_number', 64)->nullable();
            $table->string('permit_authority', 64)->nullable();
            $table->date('permit_expires_on')->nullable();
            $table->date('completion_on')->nullable();
            $table->string('developer')->nullable();
            $table->json('payment_plan')->nullable();
            $table->json('features')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'property_type']);
        });

        Schema::create('classified_packages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('type', 16);
            $table->string('name_en');
            $table->string('name_ar');
            $table->unsignedSmallInteger('duration_days')->default(7);
            $table->decimal('price', 19, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('classified_package_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('classified_ad_id')->constrained();
            $table->foreignId('classified_package_id')->constrained();
            $table->unsignedBigInteger('customer_user_id');
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->string('status', 12)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('applied_until')->nullable();
            $table->timestamps();
        });

        Schema::create('classified_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classified_ad_id')->constrained();
            $table->unsignedBigInteger('customer_user_id')->nullable();
            $table->unsignedBigInteger('seller_user_id');
            $table->string('contact_name', 120);
            $table->string('contact_email');
            $table->string('contact_phone', 24);
            $table->string('status', 12)->default('new');
            $table->foreignId('marketer_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('referral_code', 16)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['classified_ad_id', 'customer_user_id']);
            $table->index(['seller_user_id', 'status']);
        });

        Schema::create('classified_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classified_thread_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sender_user_id')->nullable();
            $table->string('sender_role', 12);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('phone_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classified_ad_id')->constrained();
            $table->unsignedBigInteger('customer_user_id');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['classified_ad_id', 'customer_user_id']);
        });

        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('name', 120);
            $table->json('filters');
            $table->string('alert_frequency', 8)->default('daily');
            $table->string('status', 8)->default('active');
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamp('checked_until')->nullable();
            $table->timestamps();
        });

        Schema::create('classified_favourites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_user_id');
            $table->foreignId('classified_ad_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_user_id', 'classified_ad_id']);
        });

        Schema::create('classified_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classified_ad_id')->constrained();
            $table->unsignedBigInteger('reporter_user_id')->nullable();
            $table->string('reason', 24);
            $table->string('details', 1000)->nullable();
            $table->string('status', 12)->default('open');
            $table->string('resolution_note')->nullable();
            $table->unsignedBigInteger('handled_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_user_id');
            $table->string('followable_type', 16);
            $table->unsignedBigInteger('followable_id');
            $table->timestamps();

            $table->unique(['customer_user_id', 'followable_type', 'followable_id']);
            $table->index(['followable_type', 'followable_id']);
        });

        Schema::create('broker_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketer_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('licence_number', 64)->nullable();
            $table->string('licence_authority', 64)->nullable();
            $table->date('licence_expires_on')->nullable();
            $table->string('office_name')->nullable();
            $table->json('areas')->nullable();
            $table->json('specialties')->nullable();
            $table->json('languages')->nullable();
            $table->timestamps();
        });

        Schema::create('broker_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketer_profile_id')->constrained();
            $table->foreignId('classified_ad_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('customer_user_id')->nullable();
            $table->string('name', 120);
            $table->string('phone', 24)->nullable();
            $table->string('email')->nullable();
            $table->string('source', 16);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status', 20)->default('new');
            $table->decimal('budget', 19, 4)->nullable();
            $table->text('notes')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamps();

            $table->index(['marketer_profile_id', 'status']);
            $table->unique(['marketer_profile_id', 'source', 'source_id'], 'broker_leads_source_unique');
        });

        Schema::create('broker_lead_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_lead_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 12);
            $table->timestamp('due_at');
            $table->string('note')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['due_at', 'done_at']);
        });

        Schema::create('property_viewings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classified_ad_id')->constrained();
            $table->unsignedBigInteger('customer_user_id')->nullable();
            $table->string('contact_name', 120);
            $table->string('contact_email');
            $table->string('contact_phone', 24);
            $table->foreignId('marketer_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('owner_user_id');
            $table->timestamp('preferred_at');
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 12)->default('requested');
            $table->string('note', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('special_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->foreignId('classified_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 12)->nullable();
            $table->string('property_type', 24)->nullable();
            $table->string('city', 80)->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->decimal('budget_min', 19, 4)->nullable();
            $table->decimal('budget_max', 19, 4)->nullable();
            $table->text('details');
            $table->string('status', 12)->default('open');
            $table->foreignId('claimed_by_marketer_profile_id')->nullable()->constrained('marketer_profiles')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('exclusive_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketer_profile_id')->constrained();
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 12)->default('scheduled');
            $table->string('file_path')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach ([
            'exclusive_contracts', 'special_requests', 'property_viewings', 'broker_lead_follow_ups', 'broker_leads', 'broker_details',
            'follows', 'classified_reports', 'classified_favourites', 'saved_searches', 'phone_reveals', 'classified_messages',
            'classified_threads', 'classified_package_purchases', 'classified_packages', 'property_details', 'classified_ad_values',
            'classified_ad_media', 'classified_ads', 'classified_attributes', 'classified_categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
    }
};
