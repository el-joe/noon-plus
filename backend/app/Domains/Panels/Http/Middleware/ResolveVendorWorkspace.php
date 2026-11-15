<?php

namespace App\Domains\Panels\Http\Middleware;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Vendors\Support\VendorWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a partner-panel request to the signed-in seller's store in the
 * current country. With `:required` the request is refused without one.
 */
class ResolveVendorWorkspace
{
    public function __construct(
        private VendorWorkspace $workspace,
        private CountryContext $countryContext,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $vendor = $this->workspace->resolve($request->user(), $this->countryContext);

        if ($mode === 'required' && $vendor === null) {
            abort(403, __('Your account is not linked to a store in this country.'));
        }

        View::share('currentVendor', $vendor);

        return $next($request);
    }
}
