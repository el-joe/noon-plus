<?php

namespace App\Domains\Payments\Support;

use App\Domains\Payments\Models\SavedPaymentCard;
use Illuminate\Support\Facades\DB;

/**
 * Saved cards (G-A6). A gateway that tokenises a card on capture reports
 * `GatewayOutcome::$card`; {@see PaymentAttempts::applyOutcome()} stores it
 * here. The first card is the default.
 */
class SavedCards
{
    /**
     * @param  array{token: string, last4: string, brand?: string|null, exp_month?: int|null, exp_year?: int|null, customer_ref?: string|null}  $card
     */
    public function remember(int $customerUserId, string $gateway, array $card): ?SavedPaymentCard
    {
        if (! preg_match('/^\d{4}$/', (string) ($card['last4'] ?? '')) || blank($card['token'] ?? null)) {
            return null;
        }

        $hash = hash('sha256', $card['token']);

        return DB::connection((new SavedPaymentCard)->getConnectionName())->transaction(function () use ($customerUserId, $gateway, $card, $hash): SavedPaymentCard {
            $hasDefault = SavedPaymentCard::query()->where('customer_user_id', $customerUserId)->where('is_default', true)->exists();

            return SavedPaymentCard::query()->updateOrCreate(
                ['customer_user_id' => $customerUserId, 'gateway' => $gateway, 'token_hash' => $hash],
                [
                    'gateway_token' => $card['token'],
                    'gateway_customer_ref' => $card['customer_ref'] ?? null,
                    'brand' => isset($card['brand']) ? mb_strtolower((string) $card['brand']) : null,
                    'last4' => $card['last4'],
                    'exp_month' => $card['exp_month'] ?? null,
                    'exp_year' => $card['exp_year'] ?? null,
                    'last_used_at' => now(),
                ] + ($hasDefault ? [] : ['is_default' => true]),
            );
        });
    }

    public function makeDefault(SavedPaymentCard $card): void
    {
        DB::connection($card->getConnectionName())->transaction(function () use ($card): void {
            SavedPaymentCard::query()->where('customer_user_id', $card->customer_user_id)->update(['is_default' => false]);
            $card->forceFill(['is_default' => true])->save();
        });
    }

    public function forget(SavedPaymentCard $card): void
    {
        $wasDefault = $card->is_default;
        $card->delete();

        if ($wasDefault) {
            SavedPaymentCard::query()->where('customer_user_id', $card->customer_user_id)->latest('last_used_at')->first()?->forceFill(['is_default' => true])->save();
        }
    }
}
