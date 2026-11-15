<?php

namespace App\Domains\Delivery\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\CarrierStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Support\DeliveryAgents;
use App\Domains\Delivery\Support\Scorecards;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Models\TeamInvitation;
use App\Domains\Panels\Support\TeamInvitations;
use App\Domains\Shipping\Models\ShippingZone;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Delivery & logistics: agents (create, verify, suspend, zones),
 * shipping companies, and the agent / carrier scorecards (SRS §4.1.11).
 */
class DeliveryNetworkController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function agents(): View
    {
        return view('panels.admin.delivery.agents', [
            'agents' => DeliveryAgent::query()->with('carrier')->withCount('assignments')->orderBy('name')->paginate(30),
            'carriers' => Carrier::query()->orderBy('name')->pluck('name', 'id'),
            'zones' => ShippingZone::query()->orderBy('name')->pluck('name', 'id'),
            'invitations' => TeamInvitation::query()->where('role', MembershipRole::DeliveryAgent)->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now())->latest('id')->get(),
            'country' => $this->countryContext->current(),
        ]);
    }

    /**
     * Wave 2: an admin adds a driver by invitation (wave 1 team invitations):
     * the person proves the inbox and sets a password (or keeps their
     * account) before the agent and membership exist. Drivers without a
     * company join the Nawy fleet.
     */
    public function storeAgent(Request $request, TeamInvitations $invitations): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'carrier_id' => ['nullable', 'integer', Rule::exists(Carrier::class, 'id')],
            'vehicle_type' => ['nullable', Rule::in(['bike', 'car', 'van', 'truck'])],
            'vehicle_plate' => ['nullable', 'string', 'max:24'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['integer', Rule::exists(ShippingZone::class, 'id')],
        ]);
        $existing = User::query()->where('email', Str::lower(trim($data['email'])))->value('id');

        if ($existing !== null && DeliveryAgent::query()->where('user_id', $existing)->exists()) {
            throw new TransitionRefused(__('This person is already a delivery agent in this country.'));
        }

        $carrier = isset($data['carrier_id']) ? Carrier::query()->findOrFail($data['carrier_id']) : null;
        $invitations->invite(
            'delivery',
            $carrier === null ? TeamInvitations::PLATFORM_TENANT : Carrier::TENANT,
            $carrier?->id ?? 0,
            $carrier?->name ?? 'Nawy',
            MembershipRole::DeliveryAgent,
            null,
            $data['email'],
            $data['name'],
            array_filter([
                'phone' => $data['phone'] ?? null,
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'zone_ids' => $data['zone_ids'] ?? null,
            ]) + ['invited_by' => 'admin'],
            $request->user()->id,
        );

        return back()->with('status', __('Invitation sent to :email — the driver appears here once they accept.', ['email' => $data['email']]));
    }

    public function revokeInvitation(int $invitation, TeamInvitations $invitations): RedirectResponse
    {
        $invitations->revoke(TeamInvitation::query()->where('role', MembershipRole::DeliveryAgent)->findOrFail($invitation));

        return back()->with('status', __('Invitation revoked.'));
    }

    public function agentStatus(Request $request, int $agent, DeliveryAgents $agents): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', Rule::enum(AgentStatus::class)], 'reason' => ['nullable', 'string', 'max:255']]);
        $agents->move(DeliveryAgent::query()->findOrFail($agent), AgentStatus::from($data['to']), ActorType::Admin, $request->user()->id, $data['reason'] ?? null);

        return back()->with('status', __('Agent status updated.'));
    }

    public function carriers(): View
    {
        return view('panels.admin.delivery.carriers', [
            'carriers' => Carrier::query()->withCount('agents')->orderBy('name')->get(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function storeCarrier(Request $request): RedirectResponse
    {
        $data = $this->validateCarrier($request);
        $data['code'] = $request->validate(['code' => ['required', 'alpha_dash', 'max:32', Rule::unique(Carrier::class, 'code')]])['code'];
        Carrier::query()->create($data + ['status' => CarrierStatus::Active]);

        return back()->with('status', __('Shipping company added.'));
    }

    public function updateCarrier(Request $request, int $carrier): RedirectResponse
    {
        $data = $this->validateCarrier($request) + $request->validate(['status' => ['required', Rule::enum(CarrierStatus::class)]]);
        Carrier::query()->findOrFail($carrier)->update($data);

        return back()->with('status', __('Shipping company saved.'));
    }

    public function scorecard(Request $request, Scorecards $scorecards): View
    {
        $data = $request->validate(['days' => ['nullable', 'integer', Rule::in([7, 30, 90])]]);
        $days = (int) ($data['days'] ?? 30);
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);

        return view('panels.admin.delivery.scorecard', [
            'agents' => $scorecards->agents($from, $to),
            'carriers' => $scorecards->carriers($from, $to),
            'days' => $days,
            'country' => $this->countryContext->current(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCarrier(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'is_internal' => ['nullable', 'boolean'],
        ]) + ['is_internal' => false];
    }
}
