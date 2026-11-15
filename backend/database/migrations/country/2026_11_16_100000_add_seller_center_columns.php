<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller Center (SRS §4.3, §5.2, §7.1) — country database: the Seller
 * Agreement acceptance stub, category contract / certification switches for
 * the listing gates (FR-LST-1), cross-dock fulfilment and seller-score strikes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->timestamp('seller_agreement_accepted_at')->nullable()->after('rating');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('requires_contract')->default(false)->after('return_window_days');
            $table->boolean('requires_certification')->default(false)->after('requires_contract');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->string('fulfilment', 16)->default('fbm')->change();
        });

        Schema::create('vendor_strikes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 32);
            $table->string('note')->nullable();
            $table->unsignedSmallInteger('points');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['vendor_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_strikes');

        Schema::table('listings', function (Blueprint $table) {
            $table->string('fulfilment', 8)->default('fbm')->change();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['requires_contract', 'requires_certification']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('seller_agreement_accepted_at');
        });
    }
};
