<?php

namespace App\Domains\FlashSales\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Enums\MarketerBonusType;
use App\Domains\FlashSales\Exceptions\FlashSaleTransitionRefused;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleMarketerInvite;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\FlashSales\Support\CreateFlashSaleEvent;
use App\Domains\FlashSales\Support\FlashSaleEventMachine;
use App\Domains\FlashSales\Support\FlashSaleMarketerInvites;
use App\Domains\FlashSales\Support\FlashSaleSubmissionMachine;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Coupons & flash sales → Flash sales (SRS §4.1.3, §10; lifecycle
 * catalogue §4.10–4.11): create events with times typed in the country's
 * local time (Oman shown under each field), move them through their
 * lifecycle and approve or reject seller offers.
 */
class FlashSaleEventController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    public function __construct(
        private CountryContext $countryContext,
        private CountryClock $clock,
        private FlashSaleEventMachine $events,
        private FlashSaleSubmissionMachine $submissions,
    ) {}

    public function index(): View
    {
        return view('panels.admin.flash-sales.index', [
            'events' => FlashSaleEvent::query()->withCount('submissions')->latest('starts_at')->paginate(20),
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
        ]);
    }

    public function show(int $event): View
    {
        $event = FlashSaleEvent::query()->with(['submissions.listing.variant.product', 'submissions.listing.vendor', 'statusHistory'])->findOrFail($event);

        return view('panels.admin.flash-sales.show', [
            'event' => $event,
            'invites' => FlashSaleMarketerInvite::query()->where('flash_sale_event_id', $event->id)->with('marketer')->orderBy('id')->get(),
            'marketers' => MarketerProfile::query()->where('global_status', MarketerStatus::Active)
                ->whereNotIn('id', FlashSaleMarketerInvite::query()->where('flash_sale_event_id', $event->id)->select('marketer_profile_id'))
                ->orderBy('display_name')->limit(500)->pluck('display_name', 'id')->all(),
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
        ]);
    }

    public function store(Request $request, CreateFlashSaleEvent $create): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'display_name' => ['nullable', 'string', 'max:64'],
            'submission_starts_at' => ['nullable', 'date_format:'.self::LOCAL_FORMAT],
            'submission_ends_at' => ['nullable', 'date_format:'.self::LOCAL_FORMAT],
            'starts_at' => ['required', 'date_format:'.self::LOCAL_FORMAT],
            'ends_at' => ['required', 'date_format:'.self::LOCAL_FORMAT],
            'max_qty_per_customer' => ['nullable', 'integer', 'between:1,100'],
            'marketer_bonus_type' => ['nullable', Rule::enum(MarketerBonusType::class)],
            'marketer_bonus_value' => ['nullable', 'required_with:marketer_bonus_type', 'decimal:0,3', 'gt:0', 'max:100000'],
        ]);

        if (isset($data['marketer_bonus_type'])) {
            $data['marketer_bonus_type'] = MarketerBonusType::from($data['marketer_bonus_type']);
        }

        try {
            $event = $create->handle(array_filter($data, fn ($value): bool => $value !== null), $request->user()->id);
        } catch (FlashSaleTransitionRefused $refused) {
            return back()->withInput()->withErrors(['action' => $refused->getMessage()]);
        }

        return redirect()->route('admin.flash-sales.show', $event->id)->with('status', __('Flash sale created as a draft.'));
    }

    public function transition(Request $request, int $event): RedirectResponse
    {
        $event = FlashSaleEvent::query()->findOrFail($event);
        $data = $request->validate([
            'to' => ['required', Rule::enum(FlashSaleEventStatus::class)],
            'reason' => [Rule::requiredIf($request->input('to') === FlashSaleEventStatus::Cancelled->value), 'nullable', 'string', 'max:255'],
        ]);

        try {
            $this->events->transition($event, FlashSaleEventStatus::from($data['to']), ActorType::Admin, $request->user()->id, $data['reason'] ?? null);
        } catch (FlashSaleTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Flash sale is now :status.', ['status' => str_replace('_', ' ', $data['to'])]));
    }

    /**
     * Invite one active marketer, or every active marketer (`marketer_profile_id` = all).
     */
    public function inviteMarketers(Request $request, int $event, FlashSaleMarketerInvites $invites): RedirectResponse
    {
        $event = FlashSaleEvent::query()->findOrFail($event);
        $data = $request->validate(['marketer_profile_id' => ['required', 'string', 'regex:/^(all|[0-9]+)$/']]);

        try {
            $count = $data['marketer_profile_id'] === 'all'
                ? $invites->inviteAllActive($event, ActorType::Admin, $request->user()->id)
                : (int) $invites->invite($event, MarketerProfile::query()->findOrFail((int) $data['marketer_profile_id']), ActorType::Admin, $request->user()->id)->wasRecentlyCreated;
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', trans_choice(':count marketer invited.|:count marketers invited.', $count));
    }

    public function approveSubmission(Request $request, int $submission): RedirectResponse
    {
        return $this->decide($request, $submission, FlashSaleSubmissionStatus::Approved, null);
    }

    public function rejectSubmission(Request $request, int $submission): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        return $this->decide($request, $submission, FlashSaleSubmissionStatus::Rejected, $reason);
    }

    private function decide(Request $request, int $submission, FlashSaleSubmissionStatus $to, ?string $reason): RedirectResponse
    {
        $submission = FlashSaleSubmission::query()->findOrFail($submission);

        try {
            $this->submissions->transition($submission, $to, ActorType::Admin, $request->user()->id, $reason);
        } catch (FlashSaleTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', $to === FlashSaleSubmissionStatus::Approved ? __('Offer approved.') : __('Offer rejected.'));
    }
}
