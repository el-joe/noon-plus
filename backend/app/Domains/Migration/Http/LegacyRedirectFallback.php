<?php

namespace App\Domains\Migration\Http;

use App\Domains\Migration\Support\LegacyRedirects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * WP11 extras: an old-platform URL that reaches Laravel (the old seller
 * portal `/{cc}/helpcenter/…`, `/{cc}/adsupport/…`, `/blog/…`, `/join`,
 * `/vendors`… on a panel or API host) answers 301 to its new website page
 * from `legacy_redirects` instead of 404. Runs only for a GET / HEAD that
 * matched no page (the 404 renderer), never for `api/*`; the target is on the
 * storefront (`nawy.payments.storefront_url`) and keeps the query string.
 */
class LegacyRedirectFallback
{
    public function __invoke(NotFoundHttpException $notFound, Request $request): ?RedirectResponse
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true) || $request->is('api/*')) {
            return null;
        }

        $entry = app(LegacyRedirects::class)->find($request->getPathInfo());

        if ($entry === null) {
            return null;
        }

        $query = $request->getQueryString();
        $target = rtrim((string) config('nawy.payments.storefront_url'), '/').$entry['to'].($query ? '?'.$query : '');

        return redirect()->away($target, $entry['status'])->header('Cache-Control', 'public, max-age=3600');
    }
}
