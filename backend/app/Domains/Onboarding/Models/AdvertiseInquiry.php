<?php

namespace App\Domains\Onboarding\Models;

use App\Domains\Onboarding\Enums\AdvertiseInquiryStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An advertise request from the portal form `/{code}/advertise/request`
 * (PORTAL_PAGES §4), handled by the ads team in admin.
 *
 * @property AdvertiseInquiryStatus $status
 */
#[Fillable([
    'number', 'status', 'name', 'email', 'phone', 'company', 'advertiser_type', 'interest', 'monthly_budget', 'message',
    'locale', 'customer_user_id', 'assigned_to_user_id', 'admin_note',
])]
class AdvertiseInquiry extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    public const ADVERTISER_TYPES = ['seller', 'brand', 'agency', 'other'];

    public const INTERESTS = ['product_ads', 'display_ads', 'both', 'not_sure'];

    /**
     * Monthly budget bands (G-W28), in the country's currency; `monthly_budget`
     * stores the band key (older free-text answers are kept as they were).
     *
     * @var array<string, array{0: int|null, 1: int|null}>
     */
    public const BUDGET_BANDS = [
        'under_1000' => [null, 1000],
        '1000_5000' => [1000, 5000],
        '5000_20000' => [5000, 20000],
        '20000_50000' => [20000, 50000],
        'over_50000' => [50000, null],
        'not_sure' => [null, null],
    ];

    /**
     * The band's label in a currency, e.g. "AED 1,000 – 5,000"; free text as is.
     */
    public static function budgetLabel(?string $budget, string $currency): ?string
    {
        if ($budget === null || ! array_key_exists($budget, self::BUDGET_BANDS)) {
            return $budget;
        }

        [$min, $max] = self::BUDGET_BANDS[$budget];

        return match (true) {
            $min === null && $max === null => __('Not sure yet'),
            $min === null => __('Under :currency :max', ['currency' => $currency, 'max' => number_format($max)]),
            $max === null => __('Over :currency :min', ['currency' => $currency, 'min' => number_format($min)]),
            default => $currency.' '.number_format($min).' – '.number_format($max),
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => AdvertiseInquiryStatus::class];
    }
}
