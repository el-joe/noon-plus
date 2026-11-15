<?php

namespace App\Domains\Panels\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel list filters are single values (QA-027): an array in the query
 * string (`?status[]=x`) is dropped instead of reaching a filter or a
 * form field as an array and failing with a 500.
 */
class ScalarQueryParameters
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $arrays = array_keys(array_filter($request->query->all(), 'is_array'));

            foreach ($arrays as $key) {
                $request->query->remove($key);
            }

            if ($arrays !== []) {
                $request->replace(array_diff_key($request->all(), array_flip($arrays)));
            }
        }

        return $next($request);
    }
}
