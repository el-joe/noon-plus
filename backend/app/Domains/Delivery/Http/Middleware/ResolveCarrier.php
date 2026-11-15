<?php

namespace App\Domains\Delivery\Http\Middleware;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\CarrierStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Identity\Enums\MembershipRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a Shipping panel request (carrier., SRS §4.7) to the shipping
 * company of the user's active `carrier_supervisor` membership in the
 * current country. Every page is scoped to that company; another company's
 * shipments, agents or claims are a 404. `owner` = the company owner
 * (`carriers.owner_user_id`), who alone creates supervisors and edits the
 * company (role-permission matrix §8).
 */
class ResolveCarrier
{
    public function __construct(private CountryContext $countryContext) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $carrier = $this->carrierFor($request->user());

        abort_if($carrier === null, 403, __('Your account is not linked to an active shipping company in this country.'));
        abort_if($mode === 'owner' && $carrier->owner_user_id !== $request->user()->id, 403, __('Only the company owner can do this.'));

        $request->attributes->set('carrier', $carrier);
        View::share('currentCarrier', $carrier);
        View::share('isCarrierOwner', $carrier->owner_user_id === $request->user()->id);

        return $next($request);
    }

    /**
     * The active (not suspended) company of the user's `carrier_supervisor`
     * membership in the current country, if any (also used by the ops app).
     */
    public function carrierFor(User $user): ?Carrier
    {
        $carrierId = $this->countryContext->has() ? $user->memberships()
            ->where('role', MembershipRole::CarrierSupervisor)->where('status', 'active')
            ->where('country_id', $this->countryContext->current()->id)
            ->where('tenant_type', Carrier::TENANT)->value('tenant_id') : null;
        $carrier = $carrierId === null ? null : Carrier::query()->find($carrierId);

        return $carrier === null || $carrier->status === CarrierStatus::Suspended ? null : $carrier;
    }
}
