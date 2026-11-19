<?php

namespace App\Domains\Support\Support;

use App\Domains\Support\Enums\TicketRequesterType;
use App\Domains\Support\Models\SupportTicket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is asking, and whose tickets they see: a customer sees their own; a
 * business partner's team sees every ticket of their tenant (store, travel
 * agency, marketer profile or shipping company).
 */
final readonly class TicketRequester
{
    public function __construct(
        public TicketRequesterType $type,
        public int $userId,
        public ?string $tenantType = null,
        public ?int $tenantId = null,
        public ?int $vendorId = null,
    ) {}

    public static function customer(int $userId): self
    {
        return new self(TicketRequesterType::Customer, $userId);
    }

    /**
     * @param  Builder<SupportTicket>  $query
     * @return Builder<SupportTicket>
     */
    public function scope(Builder $query): Builder
    {
        return $this->tenantType === null
            ? $query->where('requester_type', $this->type->value)->where('requester_user_id', $this->userId)
            : $query->where('tenant_type', $this->tenantType)->where('tenant_id', $this->tenantId);
    }
}
