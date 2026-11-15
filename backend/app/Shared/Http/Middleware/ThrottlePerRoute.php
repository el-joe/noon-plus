<?php

namespace App\Shared\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,1` with one counter per route and market (QA-015): the
 * framework key is only the user (or domain + IP), so every throttled
 * route of a host — and every market — shared one counter.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    /**
     * @param  Request  $request
     */
    protected function resolveRequestSignature($request): string
    {
        $route = $request->route();

        return sha1(implode('|', [
            parent::resolveRequestSignature($request),
            $route?->getName() ?? $route?->uri() ?? $request->path(),
            (string) $route?->parameter('market'),
        ]));
    }
}
