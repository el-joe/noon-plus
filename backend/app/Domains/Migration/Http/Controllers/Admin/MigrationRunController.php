<?php

namespace App\Domains\Migration\Http\Controllers\Admin;

use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Migration\Support\TableRules;
use App\Http\Controllers\Controller;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin → Data migration → Runs: one row per step of each `legacy:migrate`
 * run (`migration_runs`) with its counts and money sums per currency, and the
 * run detail (every table of the step, the other steps of the same run).
 */
class MigrationRunController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->only(['step', 'country']);
        $runs = DB::table('migration_runs')
            ->when($filters['step'] ?? null, fn ($query, $step) => $query->where('step', $step))
            ->when($filters['country'] ?? null, fn ($query, $country) => $query->where('countries', 'like', '%'.$country.'%'))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $runs->setCollection($runs->getCollection()->map(fn (object $run): object => self::summarise($run)));

        return view('panels.admin.migration.runs', [
            'runs' => $runs,
            'filters' => $filters,
            'steps' => TableRules::STEPS,
            'countries' => MigrationContext::allCountries(),
            'jobs' => DB::table('migration_jobs')->where('kind', 'step')->orderByDesc('id')->limit(10)->get(),
            'openQuarantine' => DB::table('migration_quarantine')->whereNull('resolved_at')->whereNull('review_status')->count(),
        ]);
    }

    public function show(int $run): View
    {
        $row = DB::table('migration_runs')->where('id', $run)->first() ?? abort(404);

        return view('panels.admin.migration.run', [
            'run' => self::summarise($row),
            'siblings' => DB::table('migration_runs')->where('run_id', $row->run_id)->orderBy('id')->get()->map(fn (object $sibling): object => self::summarise($sibling)),
            'quarantined' => DB::table('migration_quarantine')->where('last_run_id', $row->run_id)->where('step', $row->step)->count(),
        ]);
    }

    /**
     * Totals over the tables of one step: read / written / … and Σ per currency.
     */
    public static function summarise(object $run): object
    {
        $tables = json_decode((string) ($run->metrics ?? '[]'), true) ?: [];
        $totals = [];
        $sums = [];

        foreach ($tables as $counts) {
            foreach ($counts as $metric => $value) {
                if ($metric === 'sums') {
                    foreach ($value as $currency => $amount) {
                        $sums[$currency] = ($sums[$currency] ?? BigDecimal::zero())->plus(BigDecimal::of((string) $amount));
                    }

                    continue;
                }

                $totals[$metric] = ($totals[$metric] ?? 0) + (int) $value;
            }
        }

        ksort($totals);
        ksort($sums);
        $run->tables = $tables;
        $run->totals = $totals;
        $run->sums = array_map(fn (BigDecimal $sum): string => (string) $sum, $sums);

        return $run;
    }
}
