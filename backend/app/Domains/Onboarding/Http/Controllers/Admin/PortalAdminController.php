<?php

namespace App\Domains\Onboarding\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Onboarding\Enums\AdvertiseInquiryStatus;
use App\Domains\Onboarding\Models\AdvertiseInquiry;
use App\Domains\Onboarding\Support\AdvertiseInquiries;
use App\Domains\Onboarding\Support\JoinOptions;
use App\Domains\Onboarding\Support\PortalFunnel;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin portal controls (role-permission matrix §11): join options per
 * country (`join_options`), the advertise requests queue
 * (`advertise_inquiries`) and portal analytics (`portal_funnel_events`).
 */
class PortalAdminController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function joinOptions(JoinOptions $options): View
    {
        return view('panels.admin.portal.join-options', ['options' => $options->forCountry($this->countryContext->current())]);
    }

    public function saveJoinOptions(Request $request, JoinOptions $options): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['nullable', 'array'], 'enabled.*' => [Rule::in(JoinOptions::SECTIONS)]]);
        $enabled = $data['enabled'] ?? [];

        foreach (JoinOptions::SECTIONS as $section) {
            $options->set($section, in_array($section, $enabled, true), $this->countryContext->current(), $request->user()->id);
        }

        return back()->with('status', __('Join options saved for :country.', ['country' => $this->countryContext->current()->name_en]));
    }

    /**
     * Portal analytics (FR-POR-8): page views and unique visitors per portal
     * page, and each registration wizard's funnel (visitors per step, submitted).
     */
    public function analytics(Request $request, PortalFunnel $funnel): View
    {
        $days = (int) ($request->validate(['days' => ['nullable', Rule::in([7, 30, 90])]])['days'] ?? 30);

        return view('panels.admin.portal.analytics', ['report' => $funnel->report(now()->subDays($days)), 'days' => $days]);
    }

    public function inquiries(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(AdvertiseInquiryStatus::class)]]);
        $inquiries = AdvertiseInquiry::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status), fn ($query) => $query->whereIn('status', [AdvertiseInquiryStatus::New, AdvertiseInquiryStatus::Contacted]))
            ->latest('id')->paginate(25)->withQueryString();

        return view('panels.admin.portal.advertise-requests', [
            'inquiries' => $inquiries,
            'filters' => $filters,
            'statuses' => AdvertiseInquiryStatus::cases(),
            'history' => StateTransition::query()->where('subject_type', (new AdvertiseInquiry)->transitionSubject())->whereIn('subject_id', $inquiries->pluck('id'))->orderBy('id')->get()->groupBy('subject_id'),
            'actors' => User::query()->whereIn('id', $inquiries->pluck('assigned_to_user_id')->filter())->pluck('name', 'id'),
        ]);
    }

    public function moveInquiry(Request $request, int $inquiry, AdvertiseInquiries $inquiries): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(AdvertiseInquiryStatus::class)], 'note' => ['nullable', 'string', 'max:1000']]);
        $inquiries->move(AdvertiseInquiry::query()->findOrFail($inquiry), AdvertiseInquiryStatus::from($data['status']), $request->user()->id, $data['note'] ?? null);

        return back()->with('status', __('Request updated.'));
    }
}
