<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modest fashion (WP6, MP v2 §14b, Boksha audit) — country database:
 * size-guide templates (apparel_alpha, abaya_height_length, shoes, custom)
 * per category and optionally per seller, measurement fields per category,
 * and measurements captured on cart lines and copied to order items for
 * made-to-order products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('size_guide_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 24);
            $table->string('unit', 4)->default('cm');
            $table->json('columns');
            $table->json('rows');
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category_id', 'vendor_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('size_guide_template_id')->nullable()->after('lead_days')->constrained()->nullOnDelete();
        });

        Schema::create('category_measurement_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('key', 32);
            $table->string('label_en');
            $table->string('label_ar');
            $table->string('unit', 4)->default('cm');
            $table->decimal('min_value', 8, 2)->nullable();
            $table->decimal('max_value', 8, 2)->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'key']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->json('measurements')->nullable()->after('quantity');
            $table->string('measurement_note', 500)->nullable()->after('measurements');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->json('measurements')->nullable()->after('quantity');
            $table->string('measurement_note', 500)->nullable()->after('measurements');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['measurements', 'measurement_note']));
        Schema::table('cart_items', fn (Blueprint $table) => $table->dropColumn(['measurements', 'measurement_note']));
        Schema::dropIfExists('category_measurement_fields');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('size_guide_template_id');
        });
        Schema::dropIfExists('size_guide_templates');
    }
};
