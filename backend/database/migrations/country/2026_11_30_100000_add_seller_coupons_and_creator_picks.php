<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller Center coupons: a seller coupon an admin switched off keeps
 * `admin_disabled_at`, so the seller cannot switch it back on.
 * Creator Studio "My products": the marketer's own curated picks, in the
 * order shown on the creator page and the referral landing page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->timestamp('admin_disabled_at')->nullable()->after('is_public');
        });

        Schema::create('marketer_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketer_profile_id')->constrained('marketer_profiles')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['marketer_profile_id', 'listing_id']);
            $table->index(['marketer_profile_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketer_picks');

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('admin_disabled_at');
        });
    }
};
