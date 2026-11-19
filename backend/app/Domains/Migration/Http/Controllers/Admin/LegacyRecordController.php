<?php

namespace App\Domains\Migration\Http\Controllers\Admin;

use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Migration\Support\PiiMask;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Data migration → Legacy records: the rows archived as they were
 * (`legacy_records`, central and per country database), read only. Search by
 * legacy table and id; personal data masked unless revealed (logged).
 */
class LegacyRecordController extends Controller
{
    public function index(Request $request): View
    {
        $databases = [MigrationContext::CENTRAL, ...MigrationContext::allCountries()];
        $filters = $request->validate([
            'database' => ['nullable', Rule::in($databases)],
            'table' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:64'],
        ]);
        $database = $filters['database'] ?? MigrationContext::CENTRAL;
        $connection = MigrationContext::db($database);

        $records = $connection->table('legacy_records')
            ->when($filters['table'] ?? null, fn ($query, $table) => $query->where('source_table', $table))
            ->when($filters['q'] ?? null, fn ($query, $id) => $query->where('source_id', 'like', LikeSearch::contains($id)))
            ->orderBy('source_table')->orderBy('id')
            ->paginate(40, ['id', 'source_table', 'source_id', 'rule', 'reason', 'archived_at'])
            ->withQueryString();

        return view('panels.admin.migration.records', [
            'records' => $records,
            'filters' => [...$filters, 'database' => $database],
            'databases' => $databases,
            'tables' => $connection->table('legacy_records')->distinct()->orderBy('source_table')->pluck('source_table'),
        ]);
    }

    public function show(Request $request, string $database, int $record): View
    {
        abort_unless(in_array($database, [MigrationContext::CENTRAL, ...MigrationContext::allCountries()], true), 404);
        $row = MigrationContext::db($database)->table('legacy_records')->where('id', $record)->first() ?? abort(404);
        $reveal = $request->isMethod('post');

        if ($reveal) {
            activity('data_migration')->causedBy($request->user())->event('pii_revealed')
                ->withProperties(['database' => $database, 'legacy_record' => $record, 'source_table' => $row->source_table, 'source_id' => $row->source_id])
                ->log('Archived legacy record revealed');
        }

        return view('panels.admin.migration.record', [
            'record' => $row,
            'database' => $database,
            'payload' => PiiMask::row(json_decode((string) $row->payload, true) ?: [], $reveal),
            'revealed' => $reveal,
        ]);
    }
}
