<?php

namespace App\Domains\Identity\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum tokens are scoped per app (B4/W10, QA-001/QA-004): storefront and
 * customer-app tokens carry `customer`, delivery-app tokens `delivery`,
 * ops-app tokens `ops:{role}`. A token only opens its own API area —
 * `delivery/*` needs `delivery`, `ops/{role}/*` needs `ops:{role}` (403
 * from those groups' middleware), every other API route needs a customer
 * token (401 here; legacy customer tokens carry `*`) except the shared
 * notification centre.
 * Every token has a bounded lifetime per type (`nawy.tokens.*_days`, QC-047).
 */
class TokenScopes
{
    public const CUSTOMER = 'customer';

    public const DELIVERY = 'delivery';

    public const OPS_PREFIX = 'ops:';

    /**
     * @return array<int, string>
     */
    public function customerAbilities(): array
    {
        return [self::CUSTOMER];
    }

    /**
     * When a new token of this type stops working.
     */
    public function expiresAt(string $type): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays($this->lifetimeDays($type));
    }

    public function lifetimeDays(string $type): int
    {
        return max(1, (int) config("nawy.tokens.{$type}_days", 30));
    }

    /**
     * `customer`, `delivery` or `ops` from the token's abilities.
     */
    public function typeOf(PersonalAccessToken $token): string
    {
        $abilities = (array) $token->abilities;

        if (in_array(self::DELIVERY, $abilities, true)) {
            return self::DELIVERY;
        }

        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, self::OPS_PREFIX)) {
                return 'ops';
            }
        }

        return self::CUSTOMER;
    }

    /**
     * Whether a (valid) token may authenticate this request.
     */
    public function accepts(PersonalAccessToken $token, ?Request $request): bool
    {
        $type = $this->typeOf($token);

        if ($token->expires_at === null && $token->created_at !== null
            && $token->created_at->lt(now()->subDays($this->lifetimeDays($type)))) {
            return false;
        }

        if ($request === null || ! $request->is('api/*')) {
            return true;
        }

        $area = $this->areaOf($request);
        $abilities = (array) $token->abilities;

        return match ($area) {
            'delivery', 'ops', 'shared' => true,
            default => $type === self::CUSTOMER && (in_array(self::CUSTOMER, $abilities, true) || in_array('*', $abilities, true)),
        };
    }

    /**
     * The API area of a request: `delivery` and `ops` (their own middleware
     * then needs exactly `delivery` / `ops:{role}`: EnsureDeliveryAgent,
     * EnsureOpsWorkspace), `shared` (the notification centre every app
     * uses) or `customer`.
     */
    private function areaOf(Request $request): string
    {
        $segments = explode('/', Str::after($request->path(), 'api/v1/'));
        $section = $segments[1] ?? '';

        return match (true) {
            $section === 'delivery' => 'delivery',
            $section === 'ops' => 'ops',
            $section === 'me' && ($segments[2] ?? '') === 'notifications' => 'shared',
            default => self::CUSTOMER,
        };
    }
}
