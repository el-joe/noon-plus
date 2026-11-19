<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central reference data:
 *
 * - `exchange_rates`: one admin-maintained (or provider-synced) rate per
 *   currency pair and effective time — 1 base = rate quote. Used to charge
 *   gateways that cannot settle the country currency (PayPal → USD); the
 *   rate used is stored on the payment. Rows are never edited after use;
 *   a new effective row replaces the rate from its time on (audited).
 * - `domains` (extended; QC-044, MASTER_PLAN §5): hostname → storefront market
 *   (country + locale), panel host (with an optional country lock) or the
 *   portal. Read by the backend host resolution and the website proxy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 20, 10);
            $table->timestamp('effective_at');
            $table->string('source', 40)->default('manual');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'effective_at'], 'exchange_rates_pair_effective_unique');
            $table->index(['base_currency', 'quote_currency', 'effective_at'], 'exchange_rates_lookup_index');
        });

        // The `domains` table exists since the first schema (host, panel, country) but was never used; it is extended here.
        Schema::table('domains', function (Blueprint $table) {
            $table->renameColumn('host', 'hostname');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->string('hostname', 253)->change();
            $table->string('panel', 40)->nullable()->change();
            $table->string('kind', 20)->default('storefront')->after('hostname'); // storefront | panel | portal | api
            $table->string('locale', 5)->nullable()->after('country_id');
            $table->boolean('is_primary')->default(false)->after('panel');
            $table->string('redirect_to', 253)->nullable()->after('is_active');
            $table->string('tls_status', 20)->default('pending')->after('redirect_to'); // pending | issued | failed (recorded by ops)
            $table->timestamp('dns_verified_at')->nullable()->after('tls_status');
            $table->string('note', 255)->nullable()->after('dns_verified_at');
            $table->unsignedBigInteger('updated_by')->nullable()->after('note');
            $table->index(['kind', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['kind', 'country_id']);
            $table->dropColumn(['kind', 'locale', 'is_primary', 'redirect_to', 'tls_status', 'dns_verified_at', 'note', 'updated_by']);
        });
        Schema::table('domains', fn (Blueprint $table) => $table->renameColumn('hostname', 'host'));
        Schema::dropIfExists('exchange_rates');
    }
};
