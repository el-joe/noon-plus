<?php

namespace App\Domains\Support\Models;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Enums\TicketRequesterType;
use App\Domains\Support\Enums\TicketStatus;
use App\Models\User;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support ticket (QC-007, SRS §15, lifecycle catalogue §9.3) from a
 * customer or a business partner, worked by the country's support team.
 */
#[Fillable([
    'number', 'requester_type', 'requester_user_id', 'tenant_type', 'tenant_id', 'vendor_id', 'category', 'subject', 'priority', 'status',
    'assigned_admin_id', 'order_id', 'sub_order_id', 'locale', 'first_response_due_at', 'resolution_due_at', 'first_responded_at',
    'last_message_at', 'resolved_at', 'closed_at', 'rating', 'rating_comment', 'rated_at',
])]
class SupportTicket extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requester_type' => TicketRequesterType::class,
            'category' => TicketCategory::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'rating' => 'integer',
            'first_response_due_at' => 'immutable_datetime',
            'resolution_due_at' => 'immutable_datetime',
            'first_responded_at' => 'immutable_datetime',
            'last_message_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'rated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<SupportTicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    /**
     * The requester (central database).
     */
    public function requester(): ?User
    {
        return User::query()->find($this->requester_user_id);
    }

    /**
     * Past its first-response or resolution due time while still active.
     */
    public function isOverdue(): bool
    {
        if (! in_array($this->status->value, TicketStatus::activeValues(), true)) {
            return false;
        }

        $now = now();

        return ($this->first_responded_at === null && $this->first_response_due_at?->lt($now))
            || (bool) $this->resolution_due_at?->lt($now);
    }
}
