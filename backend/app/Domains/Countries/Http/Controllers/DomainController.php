<?php

namespace App\Domains\Countries\Http\Controllers;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\DomainMap;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/domains/resolve?host=` (QC-044) for the website proxy: what
 * a request host serves — a storefront market (`market` = `omn-ar`), a
 * panel alias, the portal, or a redirect host. 404 when the host is not
 * mapped (the website then uses its own default market).
 */
class DomainController extends Controller
{
    public function resolve(Request $request, DomainMap $domains): JsonResponse
    {
        $request->validate(['host' => ['required', 'string', 'max:260']]);
        $entry = $domains->resolve((string) $request->query('host'));

        if ($entry === null) {
            return ApiResponse::error('domain_not_mapped', __('This host is not mapped.'), 404);
        }

        $country = $entry['country'] === null ? null : Country::query()->where('code', $entry['country'])->first();

        return ApiResponse::success([
            'host' => $entry['hostname'],
            'kind' => $entry['kind'],
            'country' => $entry['country'],
            'locale' => $entry['locale'],
            'market' => $country !== null && $country->is_active ? $entry['market'] : null,
            'panel' => $entry['panel'],
            'is_primary' => $entry['is_primary'],
            'redirect_to' => $entry['redirect_to'],
            // The country's primary storefront host (canonical links, hreflang).
            'canonical_host' => $country === null ? null : $domains->primaryStorefront($country, $entry['locale']),
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
