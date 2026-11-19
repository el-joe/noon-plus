<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QC-013: existing orders with an open dispute take the `disputed` overlay
 * (lifecycle §2.1); it reverts when the dispute is resolved or closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->whereIn('id', DB::table('disputes')->whereIn('status', ['open', 'seller_responded', 'under_review', 'escalated'])->select('order_id'))
            ->update(['status' => 'disputed']);
    }

    public function down(): void
    {
        // The derived status is recomputed on the next sub-order or dispute move.
    }
};
