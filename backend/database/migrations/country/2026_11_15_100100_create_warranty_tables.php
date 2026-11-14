<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extended warranty plans sold at checkout (FR-CHK-2, SRS §7.7) — country database.
 * Plans are scoped to a (parent) category; purchases are platform revenue.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('warranty_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_ar');
            $table->unsignedSmallInteger('duration_months');
            $table->decimal('price', 19, 4);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('warranty_plan_id')->nullable()->after('quantity')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warranty_plan_id');
        });

        Schema::dropIfExists('warranty_plans');
    }
};
