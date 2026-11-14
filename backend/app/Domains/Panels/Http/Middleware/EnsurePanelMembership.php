<?php

namespace App\Domains\Panels\Http\Middleware;

use App\Domains\Panels\Support\Panel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a signed-in user into a panel only with an active membership for one
 * of the panel's roles, e.g. `EnsurePanelMembership::class.':partner'`.
 */
class EnsurePanelMembership
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $panelKey): Response
    {
        $user = $request->user();

        if ($user === null || ! Panel::find($panelKey)->allows($user)) {
            abort(403, __('Your account has no access to this panel.'));
        }

        return $next($request);
    }
}
