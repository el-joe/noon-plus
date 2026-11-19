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
     * QA-023: the band edges in each currency (the keys above are ids named
     * after the AED edges). OMR / KWD / BHD are worth ~10 AED, JOD ~5, EGP ~0.07.
     *
     * @var array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public const BUDGET_EDGES = [
        'AED' => [1000, 5000, 20000, 50000],
        'SAR' => [1000, 5000, 20000, 50000],
        'QAR' => [1000, 5000, 20000, 50000],
        'OMR' => [100, 500, 2000, 5000],
        'KWD' => [100, 500, 2000, 5000],
        'BHD' => [100, 500, 2000, 5000],
        'JOD' => [200, 1000, 4000, 10000],
        'EGP' => [10000, 50000, 200000, 500000],
    ];

    /**
     * The band's [min, max] in a currency.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function budgetRange(string $budget, string $currency): array
    {
        [$first, $second, $third, $fourth] = self::BUDGET_EDGES[$currency] ?? self::BUDGET_EDGES['AED'];

        return match ($budget) {
            'under_1000' => [null, $first],
            '1000_5000' => [$first, $second],
            '5000_20000' => [$second, $third],
            '20000_50000' => [$third, $fourth],
            'over_50000' => [$fourth, null],
            default => [null, null],
        };
    }

    /**
     * The band's label in a currency, e.g. "AED 1,000 – 5,000"; free text as is.
     */
    public static function budgetLabel(?string $budget, string $currency): ?string
    {
        if ($budget === null || ! array_key_exists($budget, self::BUDGET_BANDS)) {
            return $budget;
        }

        [$min, $max] = self::budgetRange($budget, $currency);

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
