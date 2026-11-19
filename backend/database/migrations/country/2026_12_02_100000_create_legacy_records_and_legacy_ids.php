<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP11 data migration (data-migration-plan §2.3): migrated rows keep their
 * legacy id for support, audit and URL redirects, and rows archived as they
 * were (no table in the new system yet) live in `legacy_records` of the
 * country they belong to.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = [
        'vendors', 'marketer_profiles', 'delivery_agents', 'carriers', 'categories', 'brands', 'products',
        'product_variants', 'listings', 'orders', 'sub_orders', 'order_items', 'pages', 'blog_posts',
        'help_articles', 'contract_signatures', 'coupons', 'ad_slots', 'shipping_zones', 'warranty_plans',
    ];

    public function up(): void
    {
        Schema::create('legacy_records', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 64);
            $table->string('source_id', 64);
            $table->string('rule', 8);
            $table->string('reason', 48);
            $table->json('payload');
            $table->char('checksum', 40);
            $table->timestamp('archived_at');
            $table->unique(['source_table', 'source_id']);
        });

        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->char('legacy_id', 36)->nullable()->index()->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['legacy_id']);
                $table->dropColumn('legacy_id');
            });
        }

        Schema::dropIfExists('legacy_records');
    }
};
