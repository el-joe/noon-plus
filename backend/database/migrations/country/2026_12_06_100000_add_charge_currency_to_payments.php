<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PayPal USD conversion: a payment charged by the gateway in another
 * currency keeps its order money in the country currency (`amount`,
 * `captured_amount`, `refunded_amount`) and records what the provider was
 * asked for (`charge_currency`, `charge_amount`) with the rate used
 * (1 country currency = `exchange_rate` charge currency). Refunds keep the
 * charge amount sent to the provider, worked out with the stored rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->char('charge_currency', 3)->nullable()->after('amount');
            $table->decimal('charge_amount', 19, 4)->nullable()->after('charge_currency');
            $table->decimal('exchange_rate', 20, 10)->nullable()->after('charge_amount');
            $table->unsignedBigInteger('exchange_rate_id')->nullable()->after('exchange_rate');
        });

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->decimal('charge_amount', 19, 4)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', fn (Blueprint $table) => $table->dropColumn('charge_amount'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['charge_currency', 'charge_amount', 'exchange_rate', 'exchange_rate_id']));
    }
};
