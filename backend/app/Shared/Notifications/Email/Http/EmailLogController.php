<?php

namespace App\Shared\Notifications\Email\Http;

use App\Http\Controllers\Controller;
use App\Shared\Notifications\Email\EmailAddress;
use App\Shared\Notifications\Email\EmailCatalogue;
use App\Shared\Notifications\Email\EmailCategory;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailSuppressions;
use App\Shared\Notifications\Email\Models\EmailLog;
use App\Shared\Notifications\Email\Models\EmailSuppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Platform → Email log (system_health.view): every catalogue email
 * with filters, failure reasons and a resend action, plus the suppression
 * list (system_health.manage to resend / add / lift).
 */
class EmailLogController extends Controller
{
    public function __construct(private Emails $emails, private EmailSuppressions $suppressions) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'event' => ['nullable', Rule::in(array_keys(EmailCatalogue::all()))],
            'status' => ['nullable', Rule::in(EmailLog::STATUSES)],
            'actor' => ['nullable', Rule::in(array_keys(EmailCatalogue::ACTORS))],
            'category' => ['nullable', Rule::in(array_column(EmailCategory::cases(), 'value'))],
            'recipient' => ['nullable', 'email', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = EmailLog::query()
            ->when($filters['event'] ?? null, fn ($query, $event) => $query->where('event', $event))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['actor'] ?? null, fn ($query, $actor) => $query->where('actor', $actor))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['recipient'] ?? null, fn ($query, $recipient) => $query->where('recipient_hash', EmailAddress::hash($recipient)))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<', Carbon::parse($to)->addDay()))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('panels.admin.platform.email-log', [
            'logs' => $logs,
            'filters' => $filters,
            'events' => EmailCatalogue::all(),
            'counts' => EmailLog::query()->where('created_at', '>=', now()->subDay())->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'suppressions' => EmailSuppression::query()->latest('id')->limit(100)->get(),
        ]);
    }

    public function resend(EmailLog $log): RedirectResponse
    {
        $new = $this->emails->resend($log);

        return back()->with('status', $new === null ? __('This email cannot be resent (sensitive content or the recipient is suppressed).') : __('Email queued again (#:id).', ['id' => $new->id]));
    }

    public function suppress(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'detail' => ['nullable', 'string', 'max:500']]);
        $this->suppressions->suppress($data['email'], 'manual', '*', 'admin', $data['detail'] ?? null, $request->user()->id);

        return back()->with('status', __('Address added to the suppression list.'));
    }

    public function lift(EmailSuppression $suppression): RedirectResponse
    {
        $suppression->delete();

        return back()->with('status', __('Address removed from the suppression list.'));
    }
}
