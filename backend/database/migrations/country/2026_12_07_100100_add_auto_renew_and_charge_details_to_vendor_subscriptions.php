<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor plan auto-renew (FR-FIN-7) and PayPal USD settlement detail.
 *
 * - `vendor_subscriptions.auto_renew` + `saved_payment_card_id`: the store
 *   opted in to have each card invoice charged to a saved card (the
 *   gateway token only, never card data).
 * - `vendor_subscription_invoices`: automatic charge attempts, the next
 *   retry and the last failure; the payment that settled the invoice with
 *   what the provider was charged (`charge_currency` / `charge_amount` at
 *   `exchange_rate`, e.g. PayPal in USD) next to the local amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('payment_preference');
            $table->unsignedBigInteger('saved_payment_card_id')->nullable()->after('auto_renew');
        });

        Schema::table('vendor_subscription_invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('auto_charge_attempts')->default(0)->after('last_reminded_at');
            $table->timestamp('next_auto_charge_at')->nullable()->after('auto_charge_attempts')->index();
            $table->string('auto_charge_failure', 255)->nullable()->after('next_auto_charge_at');
            $table->unsignedBigInteger('payment_id')->nullable()->after('paid_via');
            $table->decimal('paid_amount', 19, 4)->nullable()->after('payment_id');
            $table->char('charge_currency', 3)->nullable()->after('paid_amount');
            $table->decimal('charge_amount', 19, 4)->nullable()->after('charge_currency');
            $table->decimal('exchange_rate', 20, 10)->nullable()->after('charge_amount');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_subscription_invoices', function (Blueprint $table) {
            $table->dropIndex(['next_auto_charge_at']);
            $table->dropColumn(['auto_charge_attempts', 'next_auto_charge_at', 'auto_charge_failure', 'payment_id', 'paid_amount', 'charge_currency', 'charge_amount', 'exchange_rate']);
        });
        Schema::table('vendor_subscriptions', fn (Blueprint $table) => $table->dropColumn(['auto_renew', 'saved_payment_card_id']));
    }
};
