<?php

namespace App\Domains\Panels\Http\Controllers\Admin;

use App\Domains\Panels\Support\SystemHealth;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/**
 * Admin → Platform → System health (QC-044, Figma 99:3364, matrix
 * `system_health`): queues and Horizon, failed jobs (retry / forget),
 * scheduler last runs, per-country databases, mail, storage and cache.
 */
class SystemHealthController extends Controller
{
    public function index(SystemHealth $health): View
    {
        return view('panels.admin.platform.health', [
            'horizon' => $health->horizon(),
            'queues' => $health->queues(),
            'failed' => $health->failedJobs(),
            'schedule' => $health->schedule(),
            'databases' => $health->countryDatabases(),
            'mail' => $health->mail(),
            'storage' => $health->storage(),
            'cache' => $health->cache(),
            'checkedAt' => now(),
        ]);
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        activity('system_health')->causedBy($request->user())->event('job_retried')->withProperties(['uuid' => $uuid])->log('Failed job retried');

        return back()->with('status', __('The job was pushed back onto its queue.'));
    }

    public function forget(Request $request, string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
        activity('system_health')->causedBy($request->user())->event('job_forgotten')->withProperties(['uuid' => $uuid])->log('Failed job deleted');

        return back()->with('status', __('The failed job was deleted.'));
    }
}
