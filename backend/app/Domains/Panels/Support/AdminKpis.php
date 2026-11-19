<?php

namespace App\Domains\Panels\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Models\Order;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use Illuminate\Support\Collection;

/**
 * Admin dashboard figures per country (QC-024, Figma `96:1693`): GMV and
 * orders today (country local day; cancelled orders excluded), active
 * sellers, and pending approvals (offers waiting for review plus reviews
 * waiting for moderation). Never summed across currencies.
 */
class AdminKpis
{
    public function __construct(private CountryContext $countryContext, private MoneyFactory $money) {}

    /**
     * @param  Collection<int, Country>  $countries
     * @return Collection<int, array{country: Country, gmv: string, orders: int, active_vendors: int, pending_approvals: int}>
     */
    public function forCountries(Collection $countries): Collection
    {
        return $countries->map(fn (Country $country): array => $this->countryContext->runAs($country, function () use ($country): array {
            $dayStart = now($country->timezone)->startOfDay()->utc();
            $orders = Order::query()->where('placed_at', '>=', $dayStart)->where('status', '!=', 'cancelled');

            return [
                'country' => $country,
                'gmv' => (string) $this->money->of((string) ((clone $orders)->sum('grand_total') ?: '0'), $country->currency)->getAmount(),
                'orders' => (clone $orders)->count(),
                'active_vendors' => Vendor::query()->where('status', Vendor::STATUS_ACTIVE)->count(),
                'pending_approvals' => Listing::query()->where('status', ListingStatus::PendingReview)->count()
                    + ProductReview::query()->whereIn('status', ReviewStatus::queue())->count(),
            ];
        }));
    }
}
