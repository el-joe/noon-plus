<?php

namespace App\Domains\Support\Http\Controllers\Panel;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Support\Panel;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Enums\TicketRequesterType;
use App\Domains\Support\Http\Requests\RateTicketRequest;
use App\Domains\Support\Http\Requests\ReopenTicketRequest;
use App\Domains\Support\Http\Requests\StoreTicketRequest;
use App\Domains\Support\Http\Requests\TicketReplyRequest;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Support\SupportTickets;
use App\Domains\Support\Support\TicketRequester;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Account → Support in the partner panels (QC-007): Seller Center, Travel
 * Partner, Shipping panel and Creator Studio. The tenant's team shares its
 * tickets; another tenant's ticket is a 404. Internal notes never show.
 */
class PartnerSupportTicketController extends Controller
{
    public function __construct(private SupportTickets $tickets, private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        $requester = $this->requester($request);

        return view('panels.shared.support.index', [
            'tickets' => $requester->scope(SupportTicket::query())->orderByDesc('last_message_at')->orderByDesc('id')->paginate(20),
            'categories' => TicketCategory::for($requester->type),
            'panel' => Panel::fromRequest($request),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $requester = $this->requester($request);
        $subOrder = null;

        if (filled($request->validated('shipment'))) {
            $subOrder = $requester->vendorId === null ? null : SubOrder::query()->where('number', $request->validated('shipment'))->where('vendor_id', $requester->vendorId)->first();

            if ($subOrder === null) {
                throw ValidationException::withMessages(['shipment' => __('This shipment is not yours.')]);
            }
        }

        $ticket = $this->tickets->open($requester, TicketCategory::from($request->validated('category')), $request->validated('subject'), $request->validated('message'),
            TicketPriority::tryFrom((string) $request->validated('priority')) ?? TicketPriority::Normal, null, $subOrder, $request->file('attachments', []));

        return redirect()->route(Panel::fromRequest($request)->route('support.show'), $ticket->id)->with('status', __('Ticket :number sent to Nawy support.', ['number' => $ticket->number]));
    }

    public function show(Request $request, int $ticket): View
    {
        $model = $this->find($request, $ticket)->load(['messages', 'transitions', 'subOrder:id,number']);

        return view('panels.shared.support.show', [
            'ticket' => $model,
            'canReopen' => $this->tickets->canReopen($model),
            'panel' => Panel::fromRequest($request),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function reply(TicketReplyRequest $request, int $ticket): RedirectResponse
    {
        $this->tickets->requesterReply($this->find($request, $ticket), $this->requester($request), $request->validated('body'), $request->file('attachments', []));

        return back()->with('status', __('Reply sent.'));
    }

    public function reopen(ReopenTicketRequest $request, int $ticket): RedirectResponse
    {
        $this->tickets->reopen($this->find($request, $ticket), $this->requester($request), $request->validated('message'));

        return back()->with('status', __('The ticket is reopened.'));
    }

    public function rate(RateTicketRequest $request, int $ticket): RedirectResponse
    {
        $this->tickets->rate($this->find($request, $ticket), $this->requester($request), (int) $request->validated('rating'), $request->validated('comment'));

        return back()->with('status', __('Thank you for your feedback.'));
    }

    private function find(Request $request, int $ticket): SupportTicket
    {
        return $this->requester($request)->scope(SupportTicket::query())->findOrFail($ticket);
    }

    private function requester(Request $request): TicketRequester
    {
        $userId = $request->user()->id;

        return match (Panel::fromRequest($request)?->key) {
            'partner' => $this->vendorRequester(TicketRequesterType::Vendor, app(VendorWorkspace::class)->vendor(), $userId),
            'travel_agency' => $this->vendorRequester(TicketRequesterType::TravelAgency, $request->attributes->get('agency'), $userId),
            'carrier' => $this->carrierRequester($request->attributes->get('carrier'), $userId),
            'marketer' => new TicketRequester(TicketRequesterType::Marketer, $userId, 'marketer', app(MarketerWorkspace::class)->marketer()->id),
            default => abort(404),
        };
    }

    private function vendorRequester(TicketRequesterType $type, mixed $vendor, int $userId): TicketRequester
    {
        abort_unless($vendor instanceof Vendor, 404);

        return new TicketRequester($type, $userId, 'vendor', $vendor->id, $vendor->id);
    }

    private function carrierRequester(mixed $carrier, int $userId): TicketRequester
    {
        abort_unless($carrier instanceof Carrier, 404);

        return new TicketRequester(TicketRequesterType::Carrier, $userId, 'carrier', $carrier->id);
    }
}
