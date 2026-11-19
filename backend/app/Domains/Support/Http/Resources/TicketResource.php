<?php

namespace App\Domains\Support\Http\Resources;

use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Models\SupportTicketMessage;
use App\Domains\Support\Support\SupportTickets;
use App\Domains\Support\Support\TicketAttachments;
use App\Shared\Localization\Label;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket as its requester sees it (QC-007): no internal notes, no
 * assignee identity; attachments as signed links; history of the status.
 * The thread and history are included when `messages` is loaded.
 *
 * @mixin SupportTicket
 */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tickets = app(SupportTickets::class);
        $attachments = app(TicketAttachments::class);

        return [
            'number' => $this->number,
            'category' => $this->category->value,
            'category_label' => Label::of($this->category),
            'subject' => $this->subject,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'status_label' => Label::of($this->status),
            'order' => $this->order?->number,
            'shipment' => $this->subOrder?->number,
            'assigned' => $this->assigned_admin_id !== null,
            'reply_expected_by' => $this->first_responded_at === null ? $this->first_response_due_at?->toIso8601String() : null,
            'first_responded_at' => $this->first_responded_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'can_reply' => in_array($this->status->value, ['open', 'in_progress', 'waiting_customer'], true),
            'can_reopen' => $tickets->canReopen($this->resource),
            'reopen_until' => $tickets->reopenUntil($this->resource)?->toIso8601String(),
            'can_rate' => in_array($this->status->value, ['resolved', 'closed'], true) && $this->rated_at === null,
            'rating' => $this->rated_at === null ? null : ['score' => $this->rating, 'comment' => $this->rating_comment, 'rated_at' => $this->rated_at->toIso8601String()],
            'created_at' => $this->created_at?->toIso8601String(),
            'messages' => $this->whenLoaded('messages', fn (): array => $this->messages->reject(fn (SupportTicketMessage $message): bool => $message->is_internal)
                ->map(fn (SupportTicketMessage $message): array => [
                    'id' => $message->id,
                    'from' => $message->isFromSupport() ? 'support' : 'requester',
                    'mine' => ! $message->isFromSupport() && $message->author_user_id === $request->user()?->id,
                    'body' => $message->body,
                    'attachments' => $attachments->present($message),
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->values()->all()),
            'history' => $this->whenLoaded('messages', fn (): array => $this->transitions->map(fn (StateTransition $transition): array => [
                'from' => $transition->from_status,
                'to' => $transition->to_status,
                'by' => in_array((string) $transition->actor_type, ['admin', 'system'], true) ? 'support' : 'requester',
                'at' => $transition->created_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
