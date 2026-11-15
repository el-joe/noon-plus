<?php

namespace App\Domains\RealEstate\Http\Controllers\Studio;

use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\RealEstate\Enums\LeadStatus;
use App\Domains\RealEstate\Enums\SpecialRequestStatus;
use App\Domains\RealEstate\Models\BrokerLead;
use App\Domains\RealEstate\Models\BrokerLeadFollowUp;
use App\Domains\RealEstate\Models\SpecialRequest;
use App\Domains\RealEstate\Support\BrokerLeads;
use App\Domains\RealEstate\Support\SpecialRequests;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Creator Studio → Broker pages (spec §5.7, Figma 104:21867 leads
 * pipeline, 104:22251 special requests, 104:22573 properties). Only for a
 * workspace marketer with the broker job type; every query is scoped to
 * that broker.
 */
class BrokerStudioController extends Controller
{
    public function __construct(private MarketerWorkspace $workspace, private BrokerLeads $leads) {}

    public function leads(): View
    {
        $broker = $this->broker();
        $leads = BrokerLead::query()->where('marketer_profile_id', $broker->id)->with('ad')->latest('updated_at')->get();

        return view('panels.marketer.broker.leads', [
            'columns' => [
                'new' => $leads->where('status', LeadStatus::New),
                'contacted' => $leads->where('status', LeadStatus::Contacted),
                'viewing_scheduled' => $leads->where('status', LeadStatus::ViewingScheduled),
                'negotiating' => $leads->where('status', LeadStatus::Negotiating),
                'closed' => $leads->whereIn('status', [LeadStatus::Won, LeadStatus::Lost]),
            ],
            'dueToday' => BrokerLeadFollowUp::query()->whereNull('done_at')->where('due_at', '<=', now()->endOfDay())
                ->whereHas('lead', fn ($query) => $query->where('marketer_profile_id', $broker->id))->with('lead')->orderBy('due_at')->get(),
        ]);
    }

    public function storeLead(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:24'], 'email' => ['nullable', 'email'], 'budget' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $this->leads->manual($this->broker()->id, $data);

        return back()->with('status', __('Lead added.'));
    }

    public function moveLead(Request $request, int $lead): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:255']]);
        $this->leads->move($this->lead($lead), LeadStatus::from($data['status']), $request->user()->id, $data['reason'] ?? null);

        return back()->with('status', __('Lead moved.'));
    }

    public function followUp(Request $request, int $lead): RedirectResponse
    {
        $data = $request->validate(['channel' => ['required', 'in:call,whatsapp,meeting,email'], 'due_at' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->leads->addFollowUp($this->lead($lead), $data['channel'], CarbonImmutable::parse($data['due_at']), $data['note'] ?? null);

        return back()->with('status', __('Follow-up scheduled.'));
    }

    public function completeFollowUp(int $followUp): RedirectResponse
    {
        $followUp = BrokerLeadFollowUp::query()->whereHas('lead', fn ($query) => $query->where('marketer_profile_id', $this->broker()->id))->findOrFail($followUp);
        $this->leads->completeFollowUp($followUp);

        return back()->with('status', __('Follow-up done.'));
    }

    public function specialRequests(): View
    {
        $broker = $this->broker();

        return view('panels.marketer.broker.special-requests', [
            'open' => SpecialRequest::query()->where('status', SpecialRequestStatus::Open)->latest('id')->limit(50)->get(),
            'mine' => SpecialRequest::query()->where('claimed_by_marketer_profile_id', $broker->id)->latest('claimed_at')->get(),
        ]);
    }

    public function claim(Request $request, int $special, SpecialRequests $requests): RedirectResponse
    {
        $requests->claim(SpecialRequest::query()->findOrFail($special), $this->broker(), $request->user()->id);

        return back()->with('status', __('Request started — the customer is now one of your leads.'));
    }

    public function finish(Request $request, int $special, SpecialRequests $requests): RedirectResponse
    {
        $to = SpecialRequestStatus::from($request->validate(['status' => ['required', 'in:fulfilled,closed']])['status']);
        $requests->finish(SpecialRequest::query()->findOrFail($special), $this->broker(), $to, $request->user()->id);

        return back()->with('status', __('Request updated.'));
    }

    public function properties(): View
    {
        return view('panels.marketer.broker.properties', [
            'ads' => ClassifiedAd::query()->where('marketer_profile_id', $this->broker()->id)->with(['property', 'category'])->withCount('threads')->latest('id')->paginate(20),
        ]);
    }

    private function broker(): MarketerProfile
    {
        $broker = $this->workspace->marketer();
        abort_unless(in_array('broker', $broker->job_types ?? [], true), 403, __('These pages are for brokers.'));

        return $broker;
    }

    private function lead(int $id): BrokerLead
    {
        return BrokerLead::query()->where('marketer_profile_id', $this->broker()->id)->findOrFail($id);
    }
}
