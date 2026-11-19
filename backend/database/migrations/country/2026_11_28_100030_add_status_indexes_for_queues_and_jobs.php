<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QC-051: indexes on the columns that jobs and moderation queues filter by
 * (auto-complete reads delivered sub-orders by delivery time; admin queues
 * list by status, newest first).
 */
return new class extends Migration
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $indexes = [
        'sub_orders' => ['status', 'delivered_at'],
        'orders' => ['status', 'placed_at'],
        'product_reviews' => ['status', 'created_at'],
        'product_questions' => ['status', 'created_at'],
        'product_answers' => ['status', 'created_at'],
        'partner_applications' => ['status', 'created_at'],
        'travel_inquiries' => ['status', 'created_at'],
        'carrier_claims' => ['status', 'created_at'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columns) {
            if (Schema::hasTable($table) && ! Schema::hasIndex($table, $columns)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $table.'_'.implode('_', $columns).'_wave5_index'));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $columns) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $table.'_'.implode('_', $columns).'_wave5_index')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($table.'_'.implode('_', $columns).'_wave5_index'));
            }
        }
    }
};
