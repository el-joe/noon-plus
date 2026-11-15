<?php

namespace App\Domains\Marketers\Http\Middleware;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Marketers\Support\MarketerWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a Creator Studio request to the marketer profile in the current
 * country (agency users: the managed marketer chosen with the switcher).
 * Without a profile the request goes on; Studio pages then refuse (403)
 * and the overview shows the membership landing page.
 */
class ResolveMarketerWorkspace
{
    public function __construct(private MarketerWorkspace $workspace, private MarketerProfiles $profiles, private CountryContext $countryContext) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $marketer = $this->countryContext->has()
            ? $this->profiles->forUser($request->user(), $request->session()->get('acting_marketer_id'))
            : null;

        $this->workspace->set($marketer);
        View::share('currentMarketer', $marketer);
        View::share('agencyMarketers', $this->countryContext->has() ? $this->profiles->agencyMarketers($request->user()) : collect());

        return $next($request);
    }
}
