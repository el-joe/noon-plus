<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Support\Panel;
use App\Domains\Vendors\Support\SellerScore;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Seller Center home (SRS §4.3, Figma 90:1821): KPIs from the store's own
 * sub-orders and listings in the current country, times in country time.
 * Members without a store in any country see the generic panel landing.
 */
class SellerDashboardController extends Controller
{
    public function __invoke(
        Request $request,
        VendorWorkspace $workspace,
        CountryContext $countryContext,
        CountryClock $clock,
        SellerScore $score,
        PanelDashboardController $fallback,
    ): View {
        if (! $workspace->has()) {
            return $fallback($request);
        }

        $vendor = $workspace->vendor();
        $country = $countryContext->current();
        $localNow = $clock->inCountry($country, now());
        $from = $localNow->startOfDay()->subDays(13);

        $recentSales = SubOrder::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', '!=', SubOrderStatus::Cancelled)
            ->where('created_at', '>=', $from->utc())
            ->get(['total', 'created_at']);

        $salesByDay = collect(range(0, 13))->mapWithKeys(fn (int $offset): array => [$from->addDays($offset)->toDateString() => '0'])->all();
        foreach ($recentSales as $subOrder) {
            $day = $clock->inCountry($country, $subOrder->created_at)->toDateString();
            $salesByDay[$day] = bcadd($salesByDay[$day] ?? '0', (string) $subOrder->total, 4);
        }

        $listingCounts = $vendor->listings()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return view('panels.partner.dashboard', [
            'vendor' => $vendor,
            'country' => $country,
            'localNow' => $localNow,
            'salesToday' => $salesByDay[$localNow->toDateString()],
            'salesByDay' => $salesByDay,
            'ordersToConfirm' => SubOrder::query()->where('vendor_id', $vendor->id)->where('status', SubOrderStatus::Placed)->count(),
            'activeListings' => (int) ($listingCounts[ListingStatus::Active->value] ?? 0),
            'rejectedListings' => (int) ($listingCounts[ListingStatus::Rejected->value] ?? 0),
            'outOfStockListings' => (int) ($listingCounts[ListingStatus::OutOfStock->value] ?? 0),
            'score' => $score->for($vendor),
            'recentOrders' => SubOrder::query()->where('vendor_id', $vendor->id)->withCount('items')->latest('id')->limit(5)->get(),
            'greeting' => $this->greeting($localNow),
        ]);
    }

    private function greeting(CarbonImmutable $localNow): string
    {
        return match (true) {
            $localNow->hour < 12 => __('Good morning'),
            $localNow->hour < 18 => __('Good afternoon'),
            default => __('Good evening'),
        };
    }
}
