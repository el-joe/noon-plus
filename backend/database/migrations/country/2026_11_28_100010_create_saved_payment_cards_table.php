<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-A6: saved cards are gateway tokens only (PCI: Nawy never sees or keeps
 * the card number or CVV). The token is encrypted at rest; brand, last 4
 * and expiry are what the gateway returns for display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_payment_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('gateway', 24);
            $table->text('gateway_token');
            $table->char('token_hash', 64);
            $table->string('gateway_customer_ref', 191)->nullable();
            $table->string('brand', 24)->nullable();
            $table->char('last4', 4);
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_user_id', 'gateway', 'token_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_payment_cards');
    }
};
