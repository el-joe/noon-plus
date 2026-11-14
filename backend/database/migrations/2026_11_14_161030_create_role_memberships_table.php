<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('role_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 48);
            $table->foreignId('country_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('active');
            $table->string('tenant_type', 48)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'role', 'country_id', 'tenant_type', 'tenant_id'], 'role_memberships_unique');
            $table->index(['role', 'country_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_memberships');
    }
};
