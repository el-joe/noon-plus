<?php

namespace App\Domains\Migration\Steps;

use App\Domains\GiftCards\Support\GiftCodes;
use App\Domains\Migration\Support\MigrationContext;
use Illuminate\Support\Facades\Crypt;

/**
 * Marketing (MG7): coupons keep their exact code in every country of their
 * `country_ids` (a code already used by another coupon there is quarantined,
 * never renamed); paid ad slots sold per day become ad slots. Monthly or
 * weekly priced slots (the legacy classified "listing boost" packages) would
 * need a price recalculation, so they are archived — classified packages
 * are configured per country in the new admin.
 */
class MarketingStep extends Step
{
    public function name(): string
    {
        return 'marketing';
    }

    protected function migrateCoupons(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $codes = array_values(array_filter(array_map(fn ($countryId): ?string => $this->countries->code((string) $countryId), (array) json_decode((string) $row['country_ids'], true))));

            if ($codes === [] && $row['currency'] !== null) {
                $codes = array_filter([$this->countries->codeForCurrency((string) $row['currency'])]);
            }

            if ($codes === []) {
                $this->quarantine->put($table, $id, 'country_unresolved', 'coupon lists no launched country', null, $row);

                continue;
            }

            if (in_array($row['customer_eligibility'], ['specific_segment', 'specific_users'], true)) {
                $this->quarantine->put($table, $id, 'shape_unsupported', "eligibility {$row['customer_eligibility']} has no new equivalent", null, $row);

                continue;
            }

            foreach ($codes as $code) {
                if (! $this->context->allows($code)) {
                    continue;
                }

                if ($row['type'] === 'fixed_amount' && $row['currency'] !== null && strtoupper((string) $row['currency']) !== $this->countries->currencyOf($code)) {
                    $this->quarantine->put($table, $id, 'currency_mismatch', "fixed amount in {$row['currency']}", $code, $row);

                    continue;
                }

                $target = match ($row['scope']) {
                    'category' => ['category', array_filter([$this->ids->find('categories', (string) $row['category_id'], $code, 'categories')])],
                    'vendor' => ['all', []],
                    'product' => ['product', []],
                    default => ['all', []],
                };
                $vendorId = $row['scope'] === 'vendor' ? $this->ids->find('vendors', (string) $row['vendor_id'], $code, 'vendors') : null;

                if (($target[0] !== 'all' && $target[1] === []) || ($row['scope'] === 'vendor' && $vendorId === null)) {
                    $this->quarantine->put($table, $id, 'parent_unmigrated', "coupon {$row['scope']} target not migrated to {$code}", $code, $row);

                    continue;
                }

                $owned = $this->ids->find($table, $id, $code, 'coupons');

                if ($owned === null && MigrationContext::db($code)->table('coupons')->where('code', $row['code'])->exists()) {
                    $this->quarantine->put($table, $id, 'duplicate_code', "code {$row['code']} already used in {$code}", $code, $row);

                    continue;
                }

                $this->writer->upsert($table, $id, $code, 'coupons', [
                    'code' => mb_substr((string) $row['code'], 0, 32),
                    'description' => mb_substr((string) ($row['title_en'] ?? $row['name'] ?? $row['description'] ?? ''), 0, 255) ?: null,
                    'scope' => $row['scope'] === 'vendor' ? 'vendor' : 'platform',
                    'vendor_id' => $vendorId,
                    'funded_by' => $row['funded_by'],
                    'target_type' => $target[0],
                    'target_ids' => array_values($target[1]),
                    'first_order_only' => $row['customer_eligibility'] === 'new_customers',
                    'customer_segment' => $row['customer_eligibility'] === 'new_customers' ? 'new_customers' : 'everyone',
                    'type' => $row['type'],
                    'value' => $row['value'],
                    'min_order_amount' => $row['min_order_amount'],
                    'max_discount_amount' => $row['max_discount'],
                    'usage_limit' => $row['usage_limit_total'],
                    'per_customer_limit' => $row['usage_limit_per_customer'],
                    'starts_at' => $row['valid_from'],
                    'ends_at' => $row['valid_until'],
                    'is_active' => (bool) $row['is_active'],
                    'is_public' => true,
                    ...$this->timestamps($row),
                ]);
            }
        }
    }

    /**
     * Gift cards (R3 by currency): the legacy code is never stored in clear
     * text — it is kept as the new HMAC hash (redeemable as before) and
     * encrypted (resendable); amount and remaining balance are copied.
     * A card the new model cannot hold is quarantined: `inactive` cards
     * (never activated), cards without an expiry date or without any
     * recipient email. The legacy PIN hash and delivery details stay in
     * `legacy_records`; the transaction history (`gift_card_transactions`)
     * stays archived.
     */
    protected function migrateGiftCards(string $table): void
    {
        $codes = app(GiftCodes::class);

        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $code = $this->countries->codeForCurrency((string) $row['currency_code']);

            if ($code === null) {
                $this->quarantine->put($table, $id, 'currency_mismatch', "gift card in {$row['currency_code']}: no launched country uses it", null, $this->maskedGiftCard($row));

                continue;
            }

            if (! $this->context->allows($code)) {
                $this->context->count($table, 'skipped_other_country');

                continue;
            }

            $reason = match (true) {
                $row['status'] === 'inactive' => 'status_unmapped: inactive (never activated) card has no new equivalent',
                $row['expires_at'] === null => 'shape_unsupported: no expiry date (new gift cards always expire)',
                default => null,
            };
            $email = $row['recipient_email'] ?: $this->emailOf($row['issued_to_customer_id'] ?? null) ?: $this->emailOf($row['purchased_by_customer_id'] ?? null);

            if ($reason === null && ($email === null || $email === '')) {
                $reason = 'shape_unsupported: no recipient email';
            }

            if ($reason !== null) {
                $this->quarantine->put($table, $id, strtok($reason, ':') ?: 'unresolved', $reason, $code, $this->maskedGiftCard($row));

                continue;
            }

            $own = $this->ids->find($table, $id, $code, 'gift_cards');
            $hash = $codes->hash((string) $row['code']);
            $existing = $own !== null && $own > 0 ? MigrationContext::db($code)->table('gift_cards')->where('id', $own)->value('code_encrypted') : null;
            $encrypted = $existing !== null && rescue(fn (): string => Crypt::decryptString($existing), '', false) === (string) $row['code'] ? $existing : Crypt::encryptString((string) $row['code']);

            if ($own === null && MigrationContext::db($code)->table('gift_cards')->where('code_hash', $hash)->exists()) {
                $this->quarantine->put($table, $id, 'duplicate_code', 'the same gift card code already exists in '.$code, $code, $this->maskedGiftCard($row));

                continue;
            }

            $this->writer->upsert($table, $id, $code, 'gift_cards', [
                'number' => 'GC-L'.strtoupper(substr(str_replace('-', '', $id), 0, 20)),
                'code_hash' => $hash,
                'code_encrypted' => $encrypted,
                'code_last4' => substr($codes->normalize((string) $row['code']), -4),
                'status' => $row['status'],
                'source' => $row['source'] === 'purchased' ? 'purchase' : 'admin',
                'currency' => strtoupper((string) $row['currency_code']),
                'amount' => $row['amount'],
                'balance' => $row['remaining_balance'],
                'purchaser_user_id' => $this->userOf('customers', $row['purchased_by_customer_id'] === null ? null : (string) $row['purchased_by_customer_id']),
                'recipient_name' => $row['recipient_name'] === null ? null : mb_substr((string) $row['recipient_name'], 0, 120),
                'recipient_email' => mb_strtolower((string) $email),
                'delivered_at' => $row['delivery_sent_at'],
                'redeemed_by_user_id' => $this->userOf('customers', $row['redeemed_by_customer_id'] === null ? null : (string) $row['redeemed_by_customer_id']),
                'redeemed_at' => $row['redeemed_at'],
                'expires_at' => $row['expires_at'],
                ...$this->timestamps($row),
            ]);
            $this->keepLegacyRow($table, $id, $this->maskedGiftCard($row), $code);
            $this->context->sum($table, $row['currency_code'], (string) $row['remaining_balance']);
        }
    }

    /**
     * The legacy row without its clear-text code (only the last 4 kept).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function maskedGiftCard(array $row): array
    {
        return ['code' => '••••'.substr((string) $row['code'], -4)] + $row;
    }

    private function emailOf(mixed $customerId): ?string
    {
        $userId = $this->userOf('customers', $customerId === null ? null : (string) $customerId);
        $email = $userId === null ? null : MigrationContext::db(MigrationContext::CENTRAL)->table('users')->where('id', $userId)->where('requires_email', false)->value('email');

        return $email === null ? null : (string) $email;
    }

    protected function migratePaidAdSlots(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];

            if ($row['pricing_model'] !== 'fixed_daily' || $row['target_type'] === 'listing_promotion') {
                $this->archiveRow($table, $row, 'R1', $this->countries->resolveRow($table, $row), 'pricing_model_unsupported');

                continue;
            }

            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            if (strtoupper((string) $row['currency']) !== $this->countries->currencyOf($code)) {
                $this->quarantine->put($table, $id, 'currency_mismatch', "slot priced in {$row['currency']}", $code, $row);

                continue;
            }

            $this->writer->upsert($table, $id, $code, 'ad_slots', [
                'code' => $this->writer->unique($code, 'ad_slots', 'code', (string) $row['slot_code'], $this->ids->find($table, $id, $code, 'ad_slots'), [], 48),
                'name' => (string) $row['name'],
                'placement' => mb_substr((string) $row['target_type'], 0, 24),
                'category_id' => $row['category_id'] ? $this->ids->find('categories', (string) $row['category_id'], $code, 'categories') : null,
                'dimensions' => $row['creative_width_px'] ? $row['creative_width_px'].'x'.$row['creative_height_px'] : null,
                'price_per_day' => $row['base_rate'],
                'max_concurrent' => min(255, (int) ($row['max_concurrent'] ?? 1)),
                'is_active' => (bool) $row['is_available'],
                ...$this->timestamps($row),
            ]);
        }
    }
}
