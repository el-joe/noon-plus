<?php

namespace App\Domains\Migration\Http\Controllers\Api;

use App\Domains\Migration\Support\LegacyRedirects;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/legacy-redirects?path=/uae-en/orders/{old-id} — the 301 target
 * of an old-platform URL (WP11 `legacy_redirects`), or 404. Public and
 * cached (server side per path, and `Cache-Control` for the caller). The
 * website asks only for paths that would otherwise 404 or that look like old
 * portal URLs (website/lib/legacy-redirects.ts).
 */
class LegacyRedirectController extends Controller
{
    public function show(Request $request, LegacyRedirects $redirects): JsonResponse
    {
        // `path` must be sent; an empty, blank or root path ("/") is simply never mapped (404, not 422).
        $request->validate(['path' => ['present', 'nullable', 'string', 'max:512']]);
        $entry = $redirects->find((string) $request->query('path', ''));

        if ($entry === null) {
            return ApiResponse::error('not_found', __('Not found.'), 404)->header('Cache-Control', 'public, max-age=300');
        }

        return ApiResponse::success(['from' => $entry['from'], 'to' => $entry['to'], 'status' => $entry['status']])
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
