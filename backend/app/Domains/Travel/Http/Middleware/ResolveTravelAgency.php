<?php

namespace App\Domains\Travel\Http\Middleware;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a Travel Partner request to the agency (vendor of type `travel`)
 * named by the user's active `travel_agency` membership in the current
 * country; every agency page is scoped to it. With `:required` a request
 * without an agency is refused (the dashboard falls back to the landing page).
 */
class ResolveTravelAgency
{
    public function __construct(private CountryContext $countryContext) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $agency = $this->agencyFor($request->user());

        abort_if($agency === null && $mode === 'required', 403, __('Your account is not linked to a travel agency in this country.'));
        $request->attributes->set('agency', $agency);
        View::share('currentAgency', $agency);

        return $next($request);
    }

    /**
     * The travel agency of the user's active `travel_agency` membership in
     * the current country, if any (also used by the ops app).
     */
    public function agencyFor(User $user): ?Vendor
    {
        $agencyId = $this->countryContext->has() ? $user->memberships()
            ->where('role', MembershipRole::TravelAgency)->where('status', 'active')
            ->where('country_id', $this->countryContext->current()->id)
            ->where('tenant_type', PanelCountries::VENDOR_TENANT)->value('tenant_id') : null;

        return $agencyId === null ? null : Vendor::query()->where('type', 'travel')->find($agencyId);
    }
}
