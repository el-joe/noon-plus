<?php

namespace App\Domains\Support\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Enums\TicketRequesterType;
use App\Domains\Support\Enums\TicketStatus;
use App\Domains\Support\Http\Requests\TicketReplyRequest;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Support\SupportTickets;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Support → Tickets (QC-007, SRS §15, lifecycle §9.3): the
 * country's ticket queue with filters and SLA flags, the thread with
 * internal notes, replies (optionally waiting on the requester),
 * assignment, priority and the status moves. Account managers see their
 * vendors' tickets only (QC-019).
 */
class AdminSupportTicketController extends Controller
{
    public function __construct(private SupportTickets $tickets, private CountryContext $countryContext, private AdminVendorScope $scope) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'category' => ['nullable', Rule::enum(TicketCategory::class)],
            'requester' => ['nullable', Rule::enum(TicketRequesterType::class)],
            'assignee' => ['nullable', 'string', 'max:20'],
            'overdue' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);
        $userId = $request->user()->id;

        $tickets = $this->query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['requester'] ?? null, fn (Builder $query, string $type) => $query->where('requester_type', $type))
            ->when($filters['assignee'] ?? null, fn (Builder $query, string $assignee) => match ($assignee) {
                'me' => $query->where('assigned_admin_id', $userId),
                'unassigned' => $query->whereNull('assigned_admin_id'),
                default => $query->where('assigned_admin_id', (int) $assignee),
            })
            ->when((bool) ($filters['overdue'] ?? false), fn (Builder $query) => $this->overdue($query))
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->where(fn (Builder $search) => $search
                ->where('number', $filters['q'])->orWhere('subject', 'like', '%'.addcslashes((string) $filters['q'], '%_\\').'%')))
            ->orderByRaw("case status when 'open' then 0 when 'in_progress' then 1 when 'waiting_customer' then 2 when 'resolved' then 3 else 4 end")
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('first_response_due_at')
            ->paginate(25)->withQueryString();

        $counts = $this->query()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return view('panels.admin.support.index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'counts' => $counts,
            'overdueCount' => $this->overdue($this->query())->count(),
            'unassignedCount' => $this->query()->whereIn('status', TicketStatus::activeValues())->whereNull('assigned_admin_id')->count(),
            'admins' => $this->admins(),
            'requesters' => User::query()->whereKey($tickets->getCollection()->pluck('requester_user_id')->unique()->all())->get(['id', 'name', 'email'])->keyBy('id'),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(Request $request, int $ticket): View
    {
        $model = $this->find($ticket)->load(['messages', 'transitions', 'order:id,number', 'subOrder:id,number']);
        $people = User::query()->whereKey($model->messages->pluck('author_user_id')->push($model->requester_user_id)->filter()->unique()->all())
            ->get(['id', 'name', 'email'])->keyBy('id');

        return view('panels.admin.support.show', [
            'ticket' => $model,
            'people' => $people,
            'tenant' => $this->tenantName($model),
            'admins' => $this->admins(),
            'canManage' => $request->user()->can('tickets.manage'),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function reply(TicketReplyRequest $request, int $ticket): RedirectResponse
    {
        $this->tickets->supportReply($this->find($ticket), $request->user()->id, $request->validated('body'),
            (bool) $request->validated('internal', false), (bool) $request->validated('wait', false), $request->file('attachments', []));

        return back()->with('status', $request->boolean('internal') ? __('Internal note added.') : __('Reply sent.'));
    }

    public function assign(Request $request, int $ticket): RedirectResponse
    {
        $adminIds = $this->admins()->pluck('id')->all();
        $assignee = $request->validate(['assignee' => ['nullable', 'integer', Rule::in($adminIds)]])['assignee'] ?? null;
        $this->tickets->assign($this->find($ticket), $assignee === null ? null : (int) $assignee, $request->user()->id);

        return back()->with('status', $assignee === null ? __('The ticket is unassigned.') : __('The ticket is assigned.'));
    }

    public function priority(Request $request, int $ticket): RedirectResponse
    {
        $priority = $request->validate(['priority' => ['required', Rule::enum(TicketPriority::class)]])['priority'];
        $this->tickets->prioritise($this->find($ticket), TicketPriority::from($priority));

        return back()->with('status', __('Priority updated.'));
    }

    public function status(Request $request, int $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([TicketStatus::InProgress->value, TicketStatus::WaitingCustomer->value, TicketStatus::Resolved->value, TicketStatus::Closed->value])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->tickets->move($this->find($ticket), TicketStatus::from($data['status']), $request->user()->id, $data['note'] ?? null);

        return back()->with('status', __('Ticket status updated.'));
    }

    /**
     * @return Builder<SupportTicket>
     */
    private function query(): Builder
    {
        return $this->scope->apply(SupportTicket::query());
    }

    /**
     * @param  Builder<SupportTicket>  $query
     * @return Builder<SupportTicket>
     */
    private function overdue(Builder $query): Builder
    {
        $now = now();

        return $query->whereIn('status', TicketStatus::activeValues())->where(fn (Builder $late) => $late
            ->where(fn (Builder $response) => $response->whereNull('first_responded_at')->where('first_response_due_at', '<', $now))
            ->orWhere('resolution_due_at', '<', $now));
    }

    private function find(int $ticket): SupportTicket
    {
        return $this->query()->findOrFail($ticket);
    }

    /**
     * Admins who can be assigned: this country's admins and super admins.
     *
     * @return Collection<int, User>
     */
    private function admins(): Collection
    {
        $country = $this->countryContext->current();

        return User::query()
            ->whereHas('memberships', fn ($membership) => $membership->where('role', MembershipRole::Admin)->where('status', 'active')
                ->where(fn ($where) => $where->where('country_id', $country->id)->orWhereNull('country_id')))
            ->orderBy('name')->get(['id', 'name', 'email']);
    }

    private function tenantName(SupportTicket $ticket): ?string
    {
        return match ($ticket->tenant_type) {
            'vendor' => Vendor::query()->whereKey($ticket->tenant_id)->value('store_name'),
            'carrier' => Carrier::query()->whereKey($ticket->tenant_id)->value('name'),
            'marketer' => MarketerProfile::query()->whereKey($ticket->tenant_id)->value('display_name'),
            default => null,
        };
    }
}
