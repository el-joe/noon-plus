<?php

namespace App\Domains\Migration\Http\Controllers\Admin;

use App\Domains\Migration\Models\MigrationJob;
use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Migration\Support\MigrationJobs;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Data migration → Redirects: the 301 map (`legacy_redirects`) with
 * search, the exports written by `legacy:redirects` (JSON, nginx map) for
 * download, and a rebuild (`legacy:redirects` on the migration queue).
 */
class LegacyRedirectAdminController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::in(['storefront', 'portal'])],
            'country' => ['nullable', Rule::in(MigrationContext::allCountries())],
        ]);

        $redirects = DB::table('legacy_redirects')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($inner) => $inner->where('from_path', 'like', LikeSearch::contains($q))->orWhere('to_path', 'like', LikeSearch::contains($q))))
            ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($filters['country'] ?? null, fn ($query, $country) => $query->where('country_code', $country))
            ->orderBy('from_path')
            ->paginate(50)
            ->withQueryString();

        return view('panels.admin.migration.redirects', [
            'redirects' => $redirects,
            'filters' => $filters,
            'countries' => MigrationContext::allCountries(),
            'total' => DB::table('legacy_redirects')->count(),
            'bySource' => DB::table('legacy_redirects')->selectRaw('source, COUNT(*) AS n')->groupBy('source')->pluck('n', 'source'),
            'exports' => collect(['json' => self::exportPath('json'), 'map' => self::exportPath('map')])
                ->map(fn (string $path): ?array => Storage::disk('local')->exists($path) ? ['size' => Storage::disk('local')->size($path), 'modified' => Storage::disk('local')->lastModified($path)] : null),
            'lastRebuild' => MigrationJob::query()->where('kind', 'redirects')->latest('id')->first(),
        ]);
    }

    public function export(string $format): StreamedResponse
    {
        $path = self::exportPath($format);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'legacy-redirects.'.$format, ['Content-Type' => $format === 'json' ? 'application/json' : 'text/plain; charset=UTF-8']);
    }

    public function rebuild(Request $request, MigrationJobs $jobs): RedirectResponse
    {
        $job = $jobs->queue('redirects', $request->user());
        activity('data_migration')->causedBy($request->user())->performedOn($job)->event('redirects_rebuild_queued')->log('Legacy redirect map rebuild queued');

        return redirect()->route('admin.migration.redirects')->with('status', __('Rebuild queued on the migration queue (legacy:redirects).'));
    }

    /**
     * The files `legacy:redirects` writes (RedirectMap::store()).
     */
    public static function exportPath(string $format): string
    {
        $json = (string) config('nawy.legacy.redirects_export');

        return $format === 'json' ? $json : (preg_replace('/\.json$/', '.map', $json) ?? $json.'.map');
    }
}
