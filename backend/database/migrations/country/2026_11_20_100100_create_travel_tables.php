<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel (WP6, SRS §12.2, lifecycle catalogue §5.2–5.4) — country database:
 * packages of travel agencies (vendors of type `travel`) with itinerary,
 * inclusions and cancellation tiers; departures with seat capacity and a
 * `seats_reserved` counter changed only under a row lock; room prices per
 * person; booking requests without payment (owner decision WP6: held →
 * requested → confirmed → completed, or hold_expired / rejected /
 * cancelled) with the customer's contact details and optional traveller
 * names, modifications and inquiries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained();
            $table->string('slug', 160)->unique();
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('trip_type', 24)->default('holiday');
            $table->string('destination_city', 80);
            $table->string('destination_country', 80)->nullable();
            $table->unsignedSmallInteger('nights');
            $table->json('itinerary')->nullable();
            $table->json('inclusions')->nullable();
            $table->json('exclusions')->nullable();
            $table->json('cancellation_policy')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('contract_path')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'vendor_id']);
        });

        Schema::create('travel_departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_package_id')->constrained()->cascadeOnDelete();
            $table->date('departs_on');
            $table->date('returns_on');
            $table->unsignedInteger('seat_capacity');
            $table->unsignedInteger('seats_reserved')->default(0);
            $table->string('status', 12)->default('open');
            $table->timestamps();

            $table->index(['travel_package_id', 'departs_on']);
        });

        Schema::create('travel_departure_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_departure_id')->constrained()->cascadeOnDelete();
            $table->string('room_type', 12);
            $table->decimal('price_per_person', 19, 4);
            $table->timestamps();

            $table->unique(['travel_departure_id', 'room_type']);
        });

        Schema::create('travel_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->unsignedBigInteger('customer_user_id')->nullable()->index();
            $table->string('contact_name', 120);
            $table->string('contact_email');
            $table->string('contact_phone', 24);
            $table->string('message', 1000)->nullable();
            $table->char('access_token', 40)->unique();
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('travel_package_id')->constrained();
            $table->foreignId('travel_departure_id')->constrained();
            $table->json('rooms');
            $table->unsignedInteger('seats');
            $table->char('currency', 3);
            $table->decimal('total', 19, 4);
            $table->string('status', 20);
            $table->timestamp('hold_expires_at')->nullable();
            $table->foreignId('marketer_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('referral_code', 16)->nullable();
            $table->string('confirmation_number', 32)->nullable()->unique();
            $table->string('decision_reason')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'hold_expires_at']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('travel_travellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_booking_id')->constrained()->cascadeOnDelete();
            $table->string('full_name', 160);
            $table->timestamps();
        });

        Schema::create('travel_booking_modifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_booking_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->json('details')->nullable();
            $table->string('status', 12)->default('requested');
            $table->string('decision_note')->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('travel_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_package_id')->constrained();
            $table->foreignId('vendor_id')->constrained();
            $table->unsignedBigInteger('customer_user_id')->nullable();
            $table->string('name', 120);
            $table->string('email');
            $table->string('phone', 24);
            $table->text('message');
            $table->string('status', 12)->default('new');
            $table->foreignId('travel_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['travel_inquiries', 'travel_booking_modifications', 'travel_travellers', 'travel_bookings', 'travel_departure_rooms', 'travel_departures', 'travel_packages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
