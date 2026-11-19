<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA wave 5a lifecycle alignment:
 * - QC-032: gateway webhook events count redeliveries (`deliveries`); the
 *   status column gains `verified` and `duplicate` (no rename of existing
 *   rows is needed: received / processed / failed keep their meaning and
 *   `ignored` stays for signed events with nothing to act on).
 * - QC-035: conversions get `paid_at`; existing released conversions that
 *   the marketer's processed withdrawals already cover (oldest first) are
 *   moved to `paid` with a system history row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->unsignedInteger('deliveries')->default(1)->after('status');
        });

        Schema::table('marketer_conversions', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('released_at');
        });

        $withdrawn = DB::table('marketer_withdrawals')->where('status', 'processed')
            ->groupBy('marketer_profile_id', 'currency')
            ->selectRaw('marketer_profile_id, currency, SUM(amount) as total')->get();

        foreach ($withdrawn as $row) {
            $covered = (string) $row->total;
            $conversions = DB::table('marketer_conversions')->where('marketer_profile_id', $row->marketer_profile_id)->where('currency', $row->currency)
                ->where('status', 'released')->orderBy('released_at')->orderBy('id')->get(['id', 'net', 'bonus']);

            foreach ($conversions as $conversion) {
                $total = bcadd((string) $conversion->net, (string) $conversion->bonus, 4);

                if (bccomp($total, $covered, 4) > 0) {
                    break;
                }

                DB::table('marketer_conversions')->where('id', $conversion->id)->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
                DB::table('state_transitions')->insert([
                    'subject_type' => 'marketer_conversion',
                    'subject_id' => $conversion->id,
                    'from_status' => 'released',
                    'to_status' => 'paid',
                    'actor_type' => 'system',
                    'reason' => 'Paid out in a withdrawal (migrated)',
                    'created_at' => now(),
                ]);
                $covered = bcsub($covered, $total, 4);
            }
        }
    }

    public function down(): void
    {
        DB::table('marketer_conversions')->where('status', 'paid')->update(['status' => 'released']);

        Schema::table('marketer_conversions', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });

        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->dropColumn('deliveries');
        });
    }
};
