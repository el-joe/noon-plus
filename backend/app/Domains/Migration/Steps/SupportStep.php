<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\MigrationContext;

/**
 * Support tickets (rule R3 via the requester; plan §4.3). A ticket lives in
 * its related order's country, else its requester's single country. The
 * requester becomes the new requester type and tenant: customer → their
 * account; seller → the store (`vendor`); marketer → the marketer profile;
 * travel agency → its store (`vendor`); shipping supervisor → the carrier.
 * Delivery-agent tickets have no new requester type and are quarantined.
 * Messages move with their ticket (authors mapped; internal notes kept
 * internal). Attachments stay archived with the files.
 */
class SupportStep extends Step
{
    /**
     * Legacy ticket category → new category.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'order_issue' => 'order', 'payment_issue' => 'payment', 'account' => 'account', 'technical' => 'technical',
        'product_inquiry' => 'product', 'policy' => 'other', 'payout' => 'payouts', 'catalog' => 'selling', 'other' => 'other',
    ];

    public function name(): string
    {
        return 'support';
    }

    protected function order(): array
    {
        return ['support_tickets', 'ticket_messages'];
    }

    protected function migrateSupportTickets(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $code = $this->ticketCountry($table, $row);

            if ($code === null) {
                $this->quarantineMessages($id);

                continue;
            }

            $plan = $this->plan($row, $code);

            if (is_string($plan)) {
                $this->quarantine->put($table, $id, strtok($plan, ':') ?: 'unresolved', $plan, $code, $row);
                $this->quarantineMessages($id);

                continue;
            }

            $write = function () use ($table, $id, $code, $plan): void {
                $ticketId = $this->writer->upsert($table, $id, $code, 'support_tickets', $plan['ticket']);
                // The legacy ticket description is the new ticket's first message.
                $this->writer->upsert($table, $id.':description', $code, 'support_ticket_messages', ['support_ticket_id' => $ticketId] + $plan['description'], countMetrics: false);

                foreach ($plan['messages'] as $messageId => $message) {
                    $this->writer->upsert('ticket_messages', $messageId, $code, 'support_ticket_messages', ['support_ticket_id' => $ticketId] + $message);
                }
            };

            $this->context->dryRun ? $write() : MigrationContext::db($code)->transaction($write);
        }
    }

    /**
     * Messages move with their ticket.
     */
    protected function migrateTicketMessages(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            if (! $this->quarantine->has($table, (string) $row['id'])) {
                $this->context->count($table, 'moved_with_ticket');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function ticketCountry(string $table, array $row): ?string
    {
        $order = $row['related_order_id'] === null ? null : $this->source->find('orders', (string) $row['related_order_id']);

        if ($order === null) {
            return $this->singleCountry($table, $row);
        }

        $code = $this->countries->code((string) $order['country_id']);

        if ($code === null) {
            $this->quarantine->put($table, (string) $row['id'], 'country_unresolved', 'related order is not in a launched country', null, $row);

            return null;
        }

        if (! $this->context->allows($code)) {
            $this->context->count($table, 'skipped_other_country');

            return null;
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{ticket: array<string, mixed>, description: array<string, mixed>, messages: array<string, array<string, mixed>>}|string
     */
    private function plan(array $row, string $code): array|string
    {
        $requesterId = (string) $row['requester_user_id'];
        [$type, $userId, $tenantType, $tenantId, $vendorId] = match ($row['requester_role']) {
            'customer' => ['customer', $this->userOf('customers', $requesterId), null, null, null],
            'seller' => $this->sellerRequester($requesterId, $code),
            'marketer' => ['marketer', $this->userOf('marketers', $requesterId), 'marketer', $this->ids->find('marketers', $requesterId, $code, 'marketer_profiles'), null],
            'travel_agency' => $this->agencyRequester($requesterId, $code),
            'shipping_supervisor' => $this->carrierRequester($requesterId, $code),
            default => [null, null, null, null, null],
        };

        if ($type === null) {
            return "shape_unsupported: {$row['requester_role']} tickets have no new requester type";
        }

        if ($userId === null || ($tenantType !== null && $tenantId === null)) {
            return "owner_unresolved: {$row['requester_role']} {$requesterId} not migrated to {$code}";
        }

        $category = self::CATEGORIES[$row['category']] ?? null;

        if ($category === null) {
            return "status_unmapped: category {$row['category']}";
        }

        $orderId = $row['related_order_id'] === null ? null : $this->ids->find('orders', (string) $row['related_order_id'], $code, 'orders');
        $messages = [];
        $last = null;

        foreach ($this->source->connection()->table('ticket_messages')->where('ticket_id', $row['id'])->orderBy('created_at')->orderBy('id')->get() as $message) {
            [$authorType, $authorUserId] = $this->author((string) $message->sender_type, (string) $message->sender_id, $type, $userId);
            $messages[(string) $message->id] = [
                'author_type' => $authorType,
                'author_user_id' => $authorUserId,
                'body' => (string) $message->message,
                'is_internal' => (bool) $message->is_internal_note,
                'attachments' => null,
                'created_at' => $message->created_at ?? $row['created_at'] ?? now(),
            ];
            $last = $message->created_at ?? $last;
        }

        $locale = MigrationContext::db(MigrationContext::CENTRAL)->table('users')->where('id', $userId)->value('preferred_locale');
        $closed = $row['status'] === 'closed';

        return ['ticket' => [
            'number' => $this->writer->unique($code, 'support_tickets', 'number', (string) $row['ticket_number'], $this->ids->find('support_tickets', (string) $row['id'], $code, 'support_tickets'), [], 24),
            'requester_type' => $type,
            'requester_user_id' => $userId,
            'tenant_type' => $tenantType,
            'tenant_id' => $tenantId,
            'vendor_id' => $vendorId,
            'category' => $category,
            'subject' => mb_substr((string) $row['subject'], 0, 160),
            'priority' => $row['priority'],
            'status' => $row['status'],
            'assigned_admin_id' => $this->userOf('admins', $row['assigned_to_admin_id'] === null ? null : (string) $row['assigned_to_admin_id']),
            'order_id' => $orderId,
            'locale' => in_array($locale, ['en', 'ar'], true) ? $locale : 'en',
            'first_responded_at' => $row['first_response_at'],
            'last_message_at' => $last ?? $row['updated_at'] ?? $row['created_at'],
            'resolved_at' => $row['resolved_at'],
            'closed_at' => $closed ? ($row['updated_at'] ?? $row['resolved_at']) : null,
            'rating' => $row['satisfaction_rating'] === null ? null : max(1, min(5, (int) $row['satisfaction_rating'])),
            'rating_comment' => $row['satisfaction_comment'] === null ? null : mb_substr((string) $row['satisfaction_comment'], 0, 1000),
            'rated_at' => $row['satisfaction_rating'] === null ? null : ($row['resolved_at'] ?? $row['updated_at']),
            ...$this->timestamps($row),
        ], 'description' => [
            'author_type' => $this->actorOf($type),
            'author_user_id' => $userId,
            'body' => (string) $row['description'],
            'is_internal' => false,
            'attachments' => null,
            'created_at' => $row['created_at'] ?? now(),
        ], 'messages' => $messages];
    }

    /**
     * @return array{0: string|null, 1: int|null, 2: string|null, 3: int|null, 4: int|null}
     */
    private function sellerRequester(string $vendorAdminId, string $code): array
    {
        $admin = $this->source->find('vendor_admins', $vendorAdminId);
        $vendorId = $admin === null ? null : $this->ids->find('vendors', (string) $admin['vendor_id'], $code, 'vendors');

        return ['vendor', $this->userOf('vendor_admins', $vendorAdminId), 'vendor', $vendorId, $vendorId];
    }

    /**
     * A travel agency asks as its store's owner.
     *
     * @return array{0: string|null, 1: int|null, 2: string|null, 3: int|null, 4: int|null}
     */
    private function agencyRequester(string $agencyId, string $code): array
    {
        $vendorId = $this->ids->find('travel_agencies', $agencyId, $code, 'vendors');
        $ownerUserId = $vendorId === null ? null : ($vendorId > 0 ? (int) MigrationContext::db($code)->table('vendors')->where('id', $vendorId)->value('owner_user_id') : $vendorId);

        return ['travel_agency', $ownerUserId, 'vendor', $vendorId, $vendorId];
    }

    /**
     * @return array{0: string|null, 1: int|null, 2: string|null, 3: int|null, 4: int|null}
     */
    private function carrierRequester(string $supervisorId, string $code): array
    {
        $supervisor = $this->source->find('shipping_company_supervisors', $supervisorId);
        $carrierId = $supervisor === null ? null : $this->ids->find('shipping_companies', (string) $supervisor['shipping_company_id'], $code, 'carriers');

        return ['carrier', $this->userOf('shipping_company_supervisors', $supervisorId), 'carrier', $carrierId, null];
    }

    /**
     * The new author of a legacy message: the requester, an admin, or the system.
     *
     * @return array{0: string, 1: int|null}
     */
    private function author(string $senderType, string $senderId, string $requesterType, int $requesterUserId): array
    {
        $morph = strtolower(class_basename($senderType));

        if (in_array($morph, ['admin', 'admins'], true)) {
            return ['admin', $this->userOf('admins', $senderId)];
        }

        if ($morph === 'system' || $morph === '') {
            return ['system', null];
        }

        return [$this->actorOf($requesterType), $requesterUserId];
    }

    private function actorOf(string $requesterType): string
    {
        return match ($requesterType) {
            'travel_agency' => 'vendor',
            default => $requesterType,
        };
    }

    private function quarantineMessages(string $ticketId): void
    {
        if (! $this->source->hasTable('ticket_messages')) {
            return;
        }

        foreach ($this->source->connection()->table('ticket_messages')->where('ticket_id', $ticketId)->get() as $message) {
            $this->quarantine->put('ticket_messages', (string) $message->id, 'parent_quarantined', "ticket {$ticketId} is quarantined", null, (array) $message);
        }
    }
}
