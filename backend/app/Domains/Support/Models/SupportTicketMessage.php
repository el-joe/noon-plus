<?php

namespace App\Domains\Support\Models;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Support\Support\TicketAttachments;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a ticket thread. Internal notes (`is_internal`) are seen by
 * support staff only. `attachments` is a list of {path, name, mime, size}
 * on the private disk ({@see TicketAttachments}).
 */
#[Fillable(['support_ticket_id', 'author_type', 'author_user_id', 'body', 'is_internal', 'attachments'])]
class SupportTicketMessage extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_type' => ActorType::class,
            'is_internal' => 'boolean',
            'attachments' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * Written by support (an admin), not by the requester's side.
     */
    public function isFromSupport(): bool
    {
        return $this->author_type === ActorType::Admin || $this->author_type === ActorType::System;
    }
}
