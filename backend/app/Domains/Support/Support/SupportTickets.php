<?php

namespace App\Domains\Support\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Customers\Support\CustomerNotifications;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Support\PanelNotifications;
use App\Domains\Settings\SettingsService;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Enums\TicketRequesterType;
use App\Domains\Support\Enums\TicketStatus;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Models\SupportTicketMessage;
use App\Models\User;
use App\Shared\Localization\InLocale;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The support ticket machine (QC-007, SRS §15, lifecycle catalogue §9.3).
 *
 * - open: the requester creates it (customer, store, travel agency,
 *   marketer, shipping company); SLA due times come from the priority.
 * - open → in_progress: support assigns it or sends the first public reply.
 * - in_progress → waiting_customer: support replies asking for information;
 *   the requester's reply moves it back to in_progress.
 * - in_progress / waiting_customer → resolved → closed: support, or the
 *   system after `support.auto_close_days` (default 7).
 * - resolved → open: the requester reopens within `support.reopen_days`.
 *
 * Every move writes a history row with its actor; the requester is told
 * about support replies, the waiting state and the resolution.
 */
class SupportTickets
{
    public function __construct(
        private StateMachine $machine,
        private SettingsService $settings,
        private CountryContext $countryContext,
        private TicketAttachments $attachments,
        private CustomerNotifications $notifications,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function open(TicketRequester $requester, TicketCategory $category, string $subject, string $body, TicketPriority $priority = TicketPriority::Normal, ?Order $order = null, ?SubOrder $subOrder = null, array $files = []): SupportTicket
    {
        if (! in_array($category, TicketCategory::for($requester->type), true)) {
            throw new TransitionRefused(__('This category is not available for your account.'));
        }

        $ticket = DB::connection((new SupportTicket)->getConnectionName())->transaction(function () use ($requester, $category, $subject, $priority, $order, $subOrder): SupportTicket {
            $now = CarbonImmutable::now();
            $ticket = SupportTicket::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'requester_type' => $requester->type,
                'requester_user_id' => $requester->userId,
                'tenant_type' => $requester->tenantType,
                'tenant_id' => $requester->tenantId,
                'vendor_id' => $requester->vendorId ?? $subOrder?->vendor_id,
                'category' => $category,
                'subject' => $subject,
                'priority' => $priority,
                'status' => TicketStatus::Open,
                'order_id' => $order?->id ?? $subOrder?->order_id,
                'sub_order_id' => $subOrder?->id,
                'locale' => app()->getLocale(),
                'last_message_at' => $now,
            ] + $this->dueTimes($priority, $now));
            $ticket->forceFill(['number' => sprintf('TK-%s-%06d', $this->countryContext->current()->iso2, $ticket->id)])->save();
            $this->machine->created($ticket, $requester->type->actor(), $requester->userId, $category->value);

            return $ticket;
        });

        $ticket->messages()->create([
            'author_type' => $requester->type->actor(),
            'author_user_id' => $requester->userId,
            'body' => $body,
            'attachments' => $files === [] ? null : $this->attachments->store($ticket, $files),
        ]);

        return $ticket;
    }

    /**
     * The requester (or their team) writes in the thread. A ticket waiting
     * on them goes back to in_progress; a resolved one must be reopened.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function requesterReply(SupportTicket $ticket, TicketRequester $requester, string $body, array $files = []): SupportTicket
    {
        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            throw new TransitionRefused($ticket->status === TicketStatus::Closed
                ? __('This ticket is closed. Open a new ticket.')
                : __('This ticket is resolved. Reopen it to reply.'));
        }

        $this->write($ticket, $requester->type->actor(), $requester->userId, $body, $files);

        if ($ticket->status === TicketStatus::WaitingCustomer) {
            $this->machine->move($ticket, TicketStatus::InProgress, $requester->type->actor(), $requester->userId, 'requester replied');
        }

        return $ticket->refresh();
    }

    /**
     * Support writes in the thread. A public reply is the first response
     * (SLA) and starts work on an open ticket; with `$waitForRequester`
     * the ticket waits on the requester. Internal notes move nothing.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function supportReply(SupportTicket $ticket, int $adminUserId, string $body, bool $internal = false, bool $waitForRequester = false, array $files = []): SupportTicket
    {
        if ($ticket->status === TicketStatus::Closed && ! $internal) {
            throw new TransitionRefused(__('This ticket is closed.'));
        }

        $this->write($ticket, ActorType::Admin, $adminUserId, $body, $files, $internal);

        if ($internal) {
            return $ticket->refresh();
        }

        if ($ticket->first_responded_at === null) {
            $ticket->forceFill(['first_responded_at' => now()])->save();
        }

        if ($ticket->status === TicketStatus::Open) {
            $this->machine->move($ticket, TicketStatus::InProgress, ActorType::Admin, $adminUserId, 'first response', ['assigned_admin_id' => $ticket->assigned_admin_id ?? $adminUserId]);
        }

        if ($waitForRequester && $ticket->status === TicketStatus::InProgress) {
            $this->machine->move($ticket, TicketStatus::WaitingCustomer, ActorType::Admin, $adminUserId, 'waiting for the requester');
            $this->notify($ticket, 'waiting_customer');
        } else {
            $this->notify($ticket, 'reply');
        }

        return $ticket->refresh();
    }

    /**
     * Assign to an admin (null unassigns). An open ticket starts work.
     */
    public function assign(SupportTicket $ticket, ?int $assigneeId, int $adminUserId): SupportTicket
    {
        $ticket->forceFill(['assigned_admin_id' => $assigneeId])->save();

        if ($assigneeId !== null && $ticket->status === TicketStatus::Open) {
            $this->machine->move($ticket, TicketStatus::InProgress, ActorType::Admin, $adminUserId, 'assigned');
        }

        return $ticket->refresh();
    }

    /**
     * A new priority re-times the SLA from when the ticket was opened.
     */
    public function prioritise(SupportTicket $ticket, TicketPriority $priority): SupportTicket
    {
        $ticket->forceFill(['priority' => $priority] + $this->dueTimes($priority, CarbonImmutable::parse($ticket->created_at)))->save();

        return $ticket;
    }

    /**
     * Support moves the ticket: in_progress, waiting_customer, resolved or closed.
     */
    public function move(SupportTicket $ticket, TicketStatus $to, int $adminUserId, ?string $note = null): SupportTicket
    {
        $attributes = match ($to) {
            TicketStatus::Resolved => ['resolved_at' => now()],
            TicketStatus::Closed => ['closed_at' => now()],
            default => [],
        };
        $this->machine->move($ticket, $to, ActorType::Admin, $adminUserId, $note, $attributes);

        if (in_array($to, [TicketStatus::WaitingCustomer, TicketStatus::Resolved], true)) {
            $this->notify($ticket, $to->value);
        }

        return $ticket;
    }

    /**
     * The requester reopens a resolved ticket within the reopen window.
     */
    public function reopen(SupportTicket $ticket, TicketRequester $requester, ?string $body = null): SupportTicket
    {
        if ($ticket->status !== TicketStatus::Resolved || ! $this->canReopen($ticket)) {
            throw new TransitionRefused(__('This ticket can no longer be reopened. Open a new ticket.'));
        }

        $this->machine->move($ticket, TicketStatus::Open, $requester->type->actor(), $requester->userId, 'reopened', ['resolved_at' => null]);

        if (filled($body)) {
            $this->write($ticket, $requester->type->actor(), $requester->userId, (string) $body);
        }

        return $ticket->refresh();
    }

    public function canReopen(SupportTicket $ticket): bool
    {
        return $ticket->status === TicketStatus::Resolved && $ticket->resolved_at !== null
            && $ticket->resolved_at->addDays($this->days('reopen_days'))->isFuture();
    }

    public function reopenUntil(SupportTicket $ticket): ?CarbonImmutable
    {
        return $ticket->status === TicketStatus::Resolved ? $ticket->resolved_at?->addDays($this->days('reopen_days')) : null;
    }

    /**
     * Satisfaction rating (1–5), once, after the ticket is resolved.
     */
    public function rate(SupportTicket $ticket, TicketRequester $requester, int $rating, ?string $comment = null): SupportTicket
    {
        if (! in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            throw new TransitionRefused(__('You can rate a ticket once it is resolved.'));
        }

        if ($ticket->rated_at !== null) {
            throw new TransitionRefused(__('You have already rated this ticket.'));
        }

        $ticket->forceFill(['rating' => $rating, 'rating_comment' => $comment, 'rated_at' => now()])->save();

        return $ticket;
    }

    /**
     * Resolved tickets past `support.auto_close_days` → closed (system).
     */
    public function autoClose(): int
    {
        $cutoff = now()->subDays($this->days('auto_close_days'));
        $closed = 0;

        SupportTicket::query()->where('status', TicketStatus::Resolved->value)->where('resolved_at', '<=', $cutoff)->orderBy('id')
            ->each(function (SupportTicket $ticket) use (&$closed): void {
                try {
                    $this->machine->move($ticket, TicketStatus::Closed, ActorType::System, null, 'auto-closed', ['closed_at' => now()]);
                    $closed++;
                } catch (TransitionRefused) {
                    // Reopened meanwhile.
                }
            });

        return $closed;
    }

    /**
     * @return array{first_response_due_at: CarbonImmutable, resolution_due_at: CarbonImmutable}
     */
    public function dueTimes(TicketPriority $priority, CarbonImmutable $from): array
    {
        return [
            'first_response_due_at' => $from->addHours($this->hours('first_response_hours', $priority)),
            'resolution_due_at' => $from->addHours($this->hours('resolution_hours', $priority)),
        ];
    }

    private function hours(string $key, TicketPriority $priority): int
    {
        $configured = (array) $this->settings->get('support.'.$key, null, $this->countryContext->current());

        return max(1, (int) ($configured[$priority->value] ?? config('nawy.support.'.$key)[$priority->value]));
    }

    private function days(string $key): int
    {
        return max(1, (int) $this->settings->get('support.'.$key, config('nawy.support.'.$key), $this->countryContext->current()));
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function write(SupportTicket $ticket, ActorType $author, ?int $authorUserId, string $body, array $files = [], bool $internal = false): SupportTicketMessage
    {
        $message = $ticket->messages()->create([
            'author_type' => $author,
            'author_user_id' => $authorUserId,
            'body' => $body,
            'is_internal' => $internal,
            'attachments' => $files === [] ? null : $this->attachments->store($ticket, $files),
        ]);

        if (! $internal) {
            $ticket->forceFill(['last_message_at' => now()])->save();
        }

        return $message;
    }

    /**
     * In-app notice to the requester, in their language.
     */
    private function notify(SupportTicket $ticket, string $event): void
    {
        $locale = User::query()->whereKey($ticket->requester_user_id)->value('preferred_locale');
        $notice = fn (): array => [
            'title' => match ($event) {
                'waiting_customer' => __('Support needs more information on ticket :number', ['number' => $ticket->number]),
                'resolved' => __('Your ticket :number is resolved', ['number' => $ticket->number]),
                default => __('Support replied to your ticket :number', ['number' => $ticket->number]),
            },
            'body' => $event === 'resolved' ? __('Not solved? You can reopen it within :days days.', ['days' => $this->days('reopen_days')]) : $ticket->subject,
        ];
        $text = in_array($locale, ['en', 'ar'], true) && $locale !== app()->getLocale() ? InLocale::run($locale, $notice) : $notice();

        $data = ['ticket' => $ticket->number, 'status' => $ticket->status->value];

        if ($ticket->requester_type === TicketRequesterType::Customer) {
            $this->notifications->notify($ticket->requester_user_id, 'support_ticket.'.$event, $text['title'], $text['body'], '/account/support/'.$ticket->number, $data);

            return;
        }

        // QC-041: business requesters hear about it in their panel bell.
        $panel = match ($ticket->requester_type) {
            TicketRequesterType::Vendor => 'partner',
            TicketRequesterType::Marketer => 'marketer',
            TicketRequesterType::TravelAgency => 'travel_agency',
            TicketRequesterType::Carrier => 'carrier',
        };
        app(PanelNotifications::class)->notify($ticket->requester_user_id, $panel, 'support_ticket.'.$event, $text['title'], $text['body'], '/support/'.$ticket->id, $data);
    }
}
