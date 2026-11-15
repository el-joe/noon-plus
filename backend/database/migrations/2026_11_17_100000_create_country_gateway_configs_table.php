<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment gateways per country (ADR 6, settings catalogue §5) — central
 * database. Credentials are encrypted at rest. At most one recommended
 * gateway per country is enforced by a unique stored column; the
 * application keeps exactly one (GatewayConfigManager). MySQL refuses a
 * cascading foreign key on the base column of a stored generated column,
 * so countries cannot be deleted while they have gateways.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('country_gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 24);
            $table->boolean('is_enabled')->default(false);
            $table->string('mode', 16)->default('sandbox');
            $table->text('credentials')->nullable();
            $table->json('currencies');
            $table->decimal('min_amount', 19, 4)->nullable();
            $table->decimal('max_amount', 19, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_recommended')->default(false);
            $table->unsignedBigInteger('recommended_country_id')->nullable()->storedAs('if(is_recommended, country_id, null)');
            $table->json('fee_rule')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['country_id', 'gateway']);
            $table->unique('recommended_country_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('country_gateway_configs');
    }
};
