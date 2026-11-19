<?php

namespace App\Domains\Countries\Http\Middleware;

use App\Domains\Countries\Support\DomainMap;
use App\Domains\Panels\Support\Panel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global (QC-044): a request on a mapped alias host — a panel alias such as
 * `admin.omn.nawy.com` or any domain with `redirect_to` — is redirected
 * (302 for panels, so the country lock can change; 301 for redirect hosts)
 * to its canonical host before routing. The canonical panel hosts and the
 * API host never reach the map.
 */
class MapCustomHost
{
    public function __construct(private DomainMap $domains) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        if ($host === '' || $host === parse_url((string) config('app.url'), PHP_URL_HOST) || $this->isCanonicalPanelHost($host)) {
            return $next($request);
        }

        $entry = $this->domains->resolve($host);
        $target = $entry === null ? null : $this->domains->redirectFor($host, $request->getRequestUri());

        if ($target === null) {
            return $next($request);
        }

        return redirect()->away($target, $entry['redirect_to'] !== null ? 301 : 302);
    }

    private function isCanonicalPanelHost(string $host): bool
    {
        foreach (array_keys((array) config('nawy.panels')) as $key) {
            if (Panel::find($key)->host === $host) {
                return true;
            }
        }

        return false;
    }
}
