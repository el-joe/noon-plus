<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\MigrationContext;

/**
 * Marketers & campaigns (MG6, plan §6). Campaigns of product listings move
 * with their budgets (`commission_budget_spent`, `influencer_fixed_reserved`)
 * and fee snapshot copied as they were; travel campaigns (closed by owner
 * decision D11) and classified campaigns (new campaigns promote store
 * listings) are quarantined. Invitations keep their exact referral code —
 * registered in the central `referral_codes`, so `/r/{code}` links keep
 * working; an invitation that never had a code gets a stable internal one.
 * Conversions of order items keep gross, fee and net (gross − fee must equal
 * net). Legacy status `replaced` (no new equivalent) becomes `cancelled` and
 * soft-deleted campaigns are archived. Fields with no new column (WhatsApp
 * delivery state, fee VAT, sample quantities…) stay in `legacy_records`.
 */
class MarketersStep extends Step
{
    /**
     * Legacy campaign fee status → new fee status.
     *
     * @var array<string, string>
     */
    public const FEE_STATUSES = ['' => 'none', 'none' => 'none', 'not_applicable' => 'none', 'pending' => 'quoted', 'paid' => 'charged', 'voided' => 'voided', 'refunded' => 'refunded'];

    /**
     * Legacy influencer fixed-fee status → new status.
     *
     * @var array<string, string>
     */
    public const FIXED_STATUSES = ['' => 'none', 'none' => 'none', 'reserved' => 'reserved', 'paid' => 'settled', 'released' => 'released'];

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function name(): string
    {
        return 'marketers';
    }

    protected function order(): array
    {
        return ['marketer_campaigns', 'marketer_campaign_invitations', 'marketer_campaign_conversions'];
    }

    protected function migrateMarketerCampaigns(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];

            if ($row['deleted_at'] !== null) {
                $this->archiveRow($table, $row, 'R1', $this->countries->resolveRow($table, $row), 'soft_deleted');

                continue;
            }

            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            $plan = $this->campaignPlan($row, $code);

            if (is_string($plan)) {
                $this->quarantine->put($table, $id, strtok($plan, ':') ?: 'unresolved', $plan, $code, $row);

                continue;
            }

            $this->writer->upsert($table, $id, $code, 'marketer_campaigns', $plan);
            $this->keepLegacyRow($table, $id, $row, $code);
            $this->context->sum($table, $row['currency'], (string) $row['commission_budget_spent']);
        }
    }

    protected function migrateMarketerCampaignInvitations(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $parent = $this->parentCampaign($table, $row, 'campaign_id');

            if ($parent === null) {
                continue;
            }

            [$code, $campaignId, $campaign] = $parent;
            $profileId = $this->ids->find('marketers', (string) $row['marketer_id'], $code, 'marketer_profiles');

            if ($profileId === null) {
                $this->quarantine->put($table, $id, 'owner_unresolved', "marketer not migrated to {$code}", $code, $row);

                continue;
            }

            $referralCode = $row['referral_code'] === null || $row['referral_code'] === '' ? $this->internalCode($id) : (string) $row['referral_code'];

            if (preg_match('/^[A-Za-z0-9]{6,16}$/', $referralCode) !== 1) {
                $this->quarantine->put($table, $id, 'code_unsupported', 'referral code is not 6–16 letters or digits; /r/{code} cannot keep it', $code, $row);

                continue;
            }

            $own = $this->ids->find($table, $id, MigrationContext::CENTRAL, 'referral_codes');

            if ($this->codeTaken($referralCode, $own)) {
                $this->quarantine->put($table, $id, 'duplicate_code', "referral code {$referralCode} is already used by another link", $code, $row);

                continue;
            }

            $fixedStatus = self::FIXED_STATUSES[(string) $row['influencer_fixed_status']] ?? null;

            if ($fixedStatus === null) {
                $this->quarantine->put($table, $id, 'status_unmapped', "influencer fixed status {$row['influencer_fixed_status']}", $code, $row);

                continue;
            }

            $jobTypes = (array) json_decode((string) MigrationContext::db($code)->table('marketer_profiles')->where('id', $profileId)->value('job_types'), true);
            $effectiveType = $row['influencer_fixed_amount'] !== null ? 'influencer' : (array_values(array_intersect(['affiliate', 'broker', 'influencer'], $jobTypes))[0] ?? 'affiliate');
            $status = $row['status'] === 'replaced' ? 'cancelled' : $row['status'];
            $closedAt = in_array($row['status'], ['rejected', 'timed_out', 'cancelled', 'replaced'], true)
                ? ($row['replaced_at'] ?? $row['responded_at'] ?? $row['expires_at'] ?? $row['updated_at'])
                : null;

            $invitationId = $this->writer->upsert($table, $id, $code, 'campaign_invitations', [
                'campaign_id' => $campaignId,
                'marketer_profile_id' => $profileId,
                'effective_type' => $effectiveType,
                'source' => match ($campaign['origin']) {
                    'marketer_request' => 'request',
                    'peer' => 'peer',
                    default => 'dispatch',
                },
                'status' => $status,
                'referral_code' => $referralCode,
                'expires_at' => $row['expires_at'],
                'influencer_fixed' => $row['influencer_fixed_amount'],
                'influencer_fixed_status' => $fixedStatus,
                'rate_snapshot' => null,
                'replaced_invitation_id' => null,
                'replaced_at' => $row['replaced_at'],
                'closed_at' => $closedAt,
                'accepted_at' => $row['status'] === 'accepted' ? ($row['responded_at'] ?? $row['updated_at']) : null,
                'response_note' => ($note = $row['decline_reason'] ?? $row['marketer_note']) === null ? null : mb_substr((string) $note, 0, 255),
                'whatsapp_sent_at' => $row['whatsapp_sent_at'],
                ...$this->timestamps($row),
            ]);

            $this->writer->upsert($table, $id, MigrationContext::CENTRAL, 'referral_codes', [
                'code' => $referralCode,
                'country_id' => (int) MigrationContext::db(MigrationContext::CENTRAL)->table('countries')->where('code', $code)->value('id'),
                'invitation_id' => $invitationId,
                'created_at' => $row['created_at'] ?? now(),
            ], countMetrics: false);
            $this->keepLegacyRow($table, $id, $row, $code);
        }
    }

    protected function migrateMarketerCampaignConversions(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $parent = $this->parentCampaign($table, $row, 'campaign_id');

            if ($parent === null) {
                continue;
            }

            [$code, $campaignId, $campaign] = $parent;
            $plan = $this->conversionPlan($row, $code, $campaignId, $campaign);

            if (is_string($plan)) {
                $this->quarantine->put($table, $id, strtok($plan, ':') ?: 'unresolved', $plan, $code, $row);

                continue;
            }

            $this->writer->upsert($table, $id, $code, 'marketer_conversions', $plan);
            $this->keepLegacyRow($table, $id, $row, $code);
            $this->context->sum($table, $row['currency'], (string) $plan['gross']);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|string
     */
    private function campaignPlan(array $row, string $code): array|string
    {
        if ($row['campaign_category'] !== 'product') {
            return "shape_unsupported: {$row['campaign_category']} campaign (new campaigns promote store listings; travel campaigns closed by D11)";
        }

        $currency = strtoupper((string) $row['currency']);

        if ($currency !== $this->countries->currencyOf($code)) {
            return "currency_mismatch: campaign in {$currency}";
        }

        if ($row['vendor_listing_id'] === null) {
            return 'shape_unsupported: campaign without a store listing';
        }

        $listingId = $this->ids->find('vendor_listings', (string) $row['vendor_listing_id'], $code, 'listings');

        if ($listingId === null) {
            return 'parent_unmigrated: promoted listing not migrated';
        }

        $vendorId = $row['vendor_id'] === null ? null : $this->ids->find('vendors', (string) $row['vendor_id'], $code, 'vendors');

        if ($row['vendor_id'] !== null && $vendorId === null) {
            return "parent_unmigrated: vendor not migrated to {$code}";
        }

        $requestedBy = $row['requested_by_marketer_id'] === null ? null : $this->ids->find('marketers', (string) $row['requested_by_marketer_id'], $code, 'marketer_profiles');

        [$ownerType, $ownerId] = match ($row['owner_type']) {
            'vendor' => ['vendor', $vendorId],
            'admin', 'platform' => ['platform', null],
            'marketer' => ['marketer', $row['owner_id'] === null ? null : $this->ids->find('marketers', (string) $row['owner_id'], $code, 'marketer_profiles')],
            default => [null, null],
        };

        if ($ownerType === null || ($ownerType !== 'platform' && $ownerId === null)) {
            return "owner_unresolved: owner {$row['owner_type']} {$row['owner_id']} not migrated";
        }

        $origin = match (true) {
            $ownerType === 'marketer' => 'peer',
            $requestedBy !== null => 'marketer_request',
            $ownerType === 'platform' => 'platform',
            default => 'vendor',
        };
        $feeStatus = self::FEE_STATUSES[(string) $row['campaign_fee_status']] ?? null;

        if ($feeStatus === null) {
            return "status_unmapped: campaign fee status {$row['campaign_fee_status']}";
        }

        return [
            'number' => 'MC-L'.strtoupper(substr(str_replace('-', '', (string) $row['id']), 0, 28)),
            'origin' => $origin,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'vendor_id' => $vendorId,
            'requested_by_marketer_id' => $requestedBy,
            'listing_id' => $listingId,
            'category_id' => null,
            'title' => mb_substr((string) ($row['title'] ?: 'Campaign '.strtoupper(substr((string) $row['id'], 0, 8))), 0, 255),
            'notes' => trim(implode("\n\n", array_filter([$row['notes'], $row['vendor_ad_notes']]))) ?: null,
            'ad_types' => json_decode((string) ($row['selected_ad_types'] ?? 'null'), true),
            'planned_marketers' => null,
            'open_to_affiliates' => (bool) $row['open_to_affiliates'],
            'attribution_model' => null,
            'currency' => strtoupper((string) $row['currency']),
            'budget' => $row['max_commission_budget'],
            'budget_spent' => $row['commission_budget_spent'],
            'influencer_fixed_reserved' => $row['influencer_fixed_reserved'],
            'ends_at' => $row['ends_at'],
            'status' => $row['status'],
            'owner_paused_at' => $row['owner_paused_at'],
            'auto_approve_at' => $row['auto_approve_at'],
            'live_at' => null,
            'done_at' => $row['status'] === 'done' ? ($row['updated_at'] ?? $row['created_at']) : null,
            'influencers_settled_at' => $row['influencers_settled_at'],
            'fee_amount' => $row['marketing_fee_total'],
            'fee_snapshot' => ['legacy' => [
                'campaign_subtotal' => (string) $row['campaign_subtotal'],
                'marketing_fee_fixed' => (string) $row['marketing_fee_fixed'],
                'marketing_fee_variable' => (string) $row['marketing_fee_variable'],
                'marketing_fee_total' => (string) $row['marketing_fee_total'],
                'campaign_grand_total' => (string) $row['campaign_grand_total'],
                'commission_type' => $row['commission_type'],
                'marketer_commission_amount' => (string) $row['marketer_commission_amount'],
                'fulfillment_type' => $row['fulfillment_type'],
                'influencer_fixed_snapshot' => json_decode((string) ($row['influencer_fixed_snapshot'] ?? 'null'), true),
            ]],
            'fee_status' => $feeStatus,
            'fee_charged_at' => $feeStatus === 'charged' ? $row['campaign_fee_paid_at'] : null,
            'rejection_reason' => $row['rejection_reason'] === null ? null : mb_substr((string) $row['rejection_reason'], 0, 255),
            'created_by_user_id' => null,
            ...$this->timestamps($row),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $campaign  new campaign row
     * @return array<string, mixed>|string
     */
    private function conversionPlan(array $row, string $code, int $campaignId, array $campaign): array|string
    {
        if ($row['conversion_type'] !== 'product_sale') {
            return "shape_unsupported: {$row['conversion_type']} conversion (new conversions are order items)";
        }

        if (strtoupper((string) $row['currency']) !== $campaign['currency']) {
            return "currency_mismatch: conversion in {$row['currency']}, campaign in {$campaign['currency']}";
        }

        $invitationId = $this->ids->find('marketer_campaign_invitations', (string) $row['invitation_id'], $code, 'campaign_invitations');

        if ($invitationId === null) {
            return ($this->quarantine->has('marketer_campaign_invitations', (string) $row['invitation_id']) ? 'parent_quarantined' : 'parent_unmigrated').': invitation not migrated';
        }

        $itemId = $row['order_item_id'] === null ? null : $this->ids->find('order_items', (string) $row['order_item_id'], $code, 'order_items');

        if ($itemId === null) {
            return 'parent_unmigrated: order item not migrated';
        }

        $db = MigrationContext::db($code);
        $item = $db->table('order_items')->where('id', $itemId)->first(['sub_order_id', 'line_total']) ?? (object) ['sub_order_id' => 0, 'line_total' => '0'];
        $orderId = (int) $db->table('sub_orders')->where('id', $item->sub_order_id)->value('order_id');
        $gross = (string) ($row['gross_commission_amount'] ?? $row['commission_amount']);
        $fee = self::decimal($row['commission_fee_amount']);
        $net = (string) ($row['net_commission_amount'] ?? bcsub($gross, $fee, 4));

        if (bccomp(bcsub($gross, $fee, 4), $net, 4) !== 0) {
            return "totals_mismatch: gross {$gross} − fee {$fee} ≠ net {$net}";
        }

        $taken = $db->table('marketer_conversions')->where('source_type', 'order_item')->where('source_id', $itemId)->where('invitation_id', $invitationId)
            ->when($own = $this->ids->find('marketer_campaign_conversions', (string) $row['id'], $code, 'marketer_conversions'), fn ($query) => $query->where('id', '!=', $own))->exists();

        if ($taken) {
            return 'duplicate_conversion: the same order item is already credited to this invitation';
        }

        $status = $row['status'] === 'approved' && $row['wallet_released_at'] !== null ? 'released' : $row['status'];

        return [
            'campaign_id' => $campaignId,
            'invitation_id' => $invitationId,
            'marketer_profile_id' => (int) $db->table('campaign_invitations')->where('id', $invitationId)->value('marketer_profile_id'),
            'type' => 'product_sale',
            'source_type' => 'order_item',
            'source_id' => $itemId,
            'order_id' => $orderId,
            'sub_order_id' => (int) $item->sub_order_id,
            'attribution' => 'direct',
            'weight' => '1',
            'currency' => $campaign['currency'],
            'basis' => $row['sale_amount'] ?? $item->line_total,
            'gross' => $gross,
            'fee' => $fee,
            'net' => $net,
            'status' => $status,
            'approved_at' => $row['approved_at'],
            'released_at' => $row['wallet_released_at'],
            'paid_at' => $row['paid_at'],
            'reversed_at' => $row['reversed_at'],
            ...$this->timestamps($row),
        ];
    }

    /**
     * The country, new id and new row of a child's campaign, or null after quarantining the child.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: int, 2: array<string, mixed>}|null
     */
    private function parentCampaign(string $table, array $row, string $column): ?array
    {
        $legacyCampaignId = (string) $row[$column];
        $targets = $this->ids->targets('marketer_campaigns', $legacyCampaignId, 'marketer_campaigns');

        if ($targets === []) {
            $reason = $this->quarantine->has('marketer_campaigns', $legacyCampaignId) ? 'parent_quarantined' : 'parent_unmigrated';
            $this->quarantine->put($table, (string) $row['id'], $reason, "campaign {$legacyCampaignId} not migrated", null, $row);

            return null;
        }

        $code = (string) array_key_first($targets);

        if (! $this->context->allows($code)) {
            $this->context->count($table, 'skipped_other_country');

            return null;
        }

        $campaignId = $targets[$code];
        $campaign = $campaignId > 0 ? (array) MigrationContext::db($code)->table('marketer_campaigns')->where('id', $campaignId)->first() : ['origin' => 'vendor', 'currency' => $this->countries->currencyOf($code)];

        return [$code, $campaignId, $campaign];
    }

    /**
     * True when another link already holds the code (codes are unique across countries, case-insensitively).
     */
    private function codeTaken(string $referralCode, ?int $ownId): bool
    {
        return MigrationContext::db(MigrationContext::CENTRAL)->table('referral_codes')->where('code', $referralCode)
            ->when($ownId !== null && $ownId > 0, fn ($query) => $query->where('id', '!=', $ownId))->exists();
    }

    /**
     * A stable 10-character code for a legacy invitation that never had one (no link to keep).
     */
    private function internalCode(string $id): string
    {
        $hash = hash('sha256', 'legacy-invitation:'.$id, true);
        $code = 'L';

        for ($i = 0; $i < 9; $i++) {
            $code .= self::CODE_ALPHABET[ord($hash[$i]) % strlen(self::CODE_ALPHABET)];
        }

        return $code;
    }
}
