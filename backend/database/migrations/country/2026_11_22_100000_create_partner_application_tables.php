<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP8 portal backend (MASTER_PLAN §5a, SRS §4.2): partner applications of
 * every join section (vendor, Open Souq, travel agency, marketer, broker,
 * shipping company) with their wizard data, review and the tenant they
 * created; advertise requests from the portal form; tenant columns filled
 * from an approved application; carrier responses to claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_applications', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('type', 24);
            $table->string('status', 24)->default('draft');
            $table->char('token_hash', 64);
            $table->string('email');
            $table->string('name', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('password_hash')->nullable();
            $table->string('locale', 5)->default('en');
            $table->string('store_slug', 120)->nullable();
            $table->json('data')->nullable();
            $table->json('completed_steps')->nullable();
            $table->string('logo_path')->nullable();
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->string('decision_reason', 1000)->nullable();
            $table->json('requested_changes')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('tenant_type', 24)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['email', 'type']);
            $table->index('store_slug');
        });

        Schema::create('advertise_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('status', 16)->default('new');
            $table->string('name', 120);
            $table->string('email');
            $table->string('phone', 32);
            $table->string('company', 160)->nullable();
            $table->string('advertiser_type', 24);
            $table->string('interest', 24);
            $table->string('monthly_budget', 40)->nullable();
            $table->string('message', 1000)->nullable();
            $table->string('locale', 5)->default('en');
            $table->unsignedBigInteger('customer_user_id')->nullable();
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->string('admin_note', 1000)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->string('contact_phone', 32)->nullable()->after('store_name');
            $table->string('logo_path')->nullable()->after('contact_phone');
            $table->json('business_details')->nullable()->after('logo_path');
        });

        Schema::table('carriers', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_user_id')->nullable()->after('id');
            $table->string('licence_number', 64)->nullable()->after('name');
            $table->json('details')->nullable()->after('status');
        });

        Schema::table('marketer_profiles', function (Blueprint $table) {
            $table->json('details')->nullable()->after('category_ids');
        });

        Schema::table('carrier_claims', function (Blueprint $table) {
            $table->string('carrier_response', 1000)->nullable()->after('decision_note');
            $table->timestamp('carrier_responded_at')->nullable()->after('carrier_response');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_claims', fn (Blueprint $table) => $table->dropColumn(['carrier_response', 'carrier_responded_at']));
        Schema::table('marketer_profiles', fn (Blueprint $table) => $table->dropColumn('details'));
        Schema::table('carriers', fn (Blueprint $table) => $table->dropColumn(['owner_user_id', 'licence_number', 'details']));
        Schema::table('vendors', fn (Blueprint $table) => $table->dropColumn(['contact_phone', 'logo_path', 'business_details']));
        Schema::dropIfExists('advertise_inquiries');
        Schema::dropIfExists('partner_applications');
    }
};
