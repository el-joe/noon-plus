<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP11 performance pass: indexes for hot filters and sorts that had none —
 * the hourly analytics aggregate / prune (ad_events, orders, referral_clicks),
 * "My orders", the default product sort, and the daily expiry jobs (coupons,
 * conversions release, campaign completion, exclusive contracts, documents),
 * plus the support-ticket lists sorted by last message.
 */
return new class extends Migration
{
    /**
     * Table => indexes (a string key is the index name, for names MySQL would find too long).
     *
     * @var array<string, array<int|string, array<int, string>>>
     */
    private array $indexes = [
        'ad_events' => [['created_at']],
        'orders' => [['placed_at', 'id'], ['customer_user_id', 'placed_at']],
        'products' => [['status', 'created_at']],
        'referral_clicks' => [['clicked_at']],
        'coupons' => [['is_active', 'ends_at']],
        'marketer_conversions' => [['status', 'approved_at']],
        'marketer_campaigns' => [['status', 'ends_at']],
        'exclusive_contracts' => [['status', 'ends_at']],
        'documents' => [['status', 'valid_until']],
        'support_tickets' => ['support_tickets_requester_last_message_index' => ['requester_type', 'requester_user_id', 'last_message_at'], 'support_tickets_tenant_last_message_index' => ['tenant_type', 'tenant_id', 'last_message_at']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $name => $indexes) {
            Schema::table($name, function (Blueprint $table) use ($indexes) {
                foreach ($indexes as $name => $columns) {
                    $table->index($columns, is_string($name) ? $name : null);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $name => $indexes) {
            Schema::table($name, function (Blueprint $table) use ($indexes) {
                foreach ($indexes as $name => $columns) {
                    $table->dropIndex(is_string($name) ? $name : $columns);
                }
            });
        }
    }
};
