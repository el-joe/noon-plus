<?php

namespace App\Domains\Travel\Support;

use App\Domains\Advertising\Support\TravelSponsoredRanker;
use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Shared\Http\LikeSearch;
use App\Shared\Http\PublicUrl;
use Illuminate\Database\Eloquent\Builder;

/**
 * Travel lists for the storefront, the app and page-builder blocks (D14):
 * trips on sale matching the filters, the promoted ones first on page 1
 * ({@see TravelSponsoredRanker}: capped, rotating, labelled), then every
 * other trip in the normal order (newest approval first). A promoted trip
 * is left out of the organic pages, so it never shows twice.
 */
class TravelCatalog
{
    public function __construct(private TravelSponsoredRanker $ranker) {}

    /**
     * @param  array{destination?: string|null, trip_type?: string|null, month?: string|null}  $filters
     * @return Builder<TravelPackage>
     */
    public function query(array $filters = []): Builder
    {
        return TravelPackage::query()->whereIn('travel_packages.status', [PackageStatus::Active, PackageStatus::SoldOut])
            ->whereHas('vendor', fn ($vendor) => $vendor->where('status', 'active'))
            ->when($filters['destination'] ?? null, fn ($query, $destination) => $query->where('destination_city', 'like', LikeSearch::contains($destination)))
            ->when($filters['trip_type'] ?? null, fn ($query, $type) => $query->where('trip_type', $type))
            ->when($filters['month'] ?? null, fn ($query, $month) => $query->whereHas('departures', fn ($departure) => $departure->where('departs_on', 'like', $month.'%')));
    }

    /**
     * One page of the list: promoted trips first on page 1, then organic.
     *
     * @param  array{destination?: string|null, trip_type?: string|null, month?: string|null}  $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, sponsored: int}
     */
    public function page(array $filters, int $page = 1, int $perPage = 24): array
    {
        $page = max(1, $page);
        $sponsoredIds = $this->ranker->pick($this->query($filters), countImpressions: $page === 1);
        $organic = $this->query($filters)->whereKeyNot($sponsoredIds)->with(['vendor', 'departures.rooms'])
            ->latest('approved_at')->orderByDesc('id')->paginate($perPage, page: $page);

        $items = collect($organic->items())->map(fn (TravelPackage $package): array => $this->card($package))->all();

        if ($page === 1 && $sponsoredIds !== []) {
            $label = $this->ranker->label();
            $promoted = TravelPackage::query()->whereKey($sponsoredIds)->with(['vendor', 'departures.rooms'])->get()
                ->sortBy(fn (TravelPackage $package): int => (int) array_search($package->id, $sponsoredIds, true))
                ->map(fn (TravelPackage $package): array => $this->card($package, $label))->values()->all();
            $items = [...$promoted, ...$items];
        }

        return ['items' => $items, 'total' => $organic->total() + count($sponsoredIds), 'page' => $organic->currentPage(), 'sponsored' => $page === 1 ? count($sponsoredIds) : 0];
    }

    /**
     * Up to `$limit` other trips like this one (same destination or trip
     * type), promoted ones first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function related(TravelPackage $package, int $limit = 4): array
    {
        $similar = fn (): Builder => $this->query()->whereKeyNot($package->id)
            ->where(fn ($query) => $query->where('destination_city', $package->destination_city)->orWhere('trip_type', $package->trip_type));
        $sponsoredIds = $this->ranker->pick($similar(), $limit);
        $label = $this->ranker->label();
        $promoted = $sponsoredIds === [] ? collect() : TravelPackage::query()->whereKey($sponsoredIds)->with(['vendor', 'departures.rooms'])->get()
            ->sortBy(fn (TravelPackage $item): int => (int) array_search($item->id, $sponsoredIds, true))
            ->map(fn (TravelPackage $item): array => $this->card($item, $label))->values();
        $organic = $similar()->whereKeyNot($sponsoredIds)->with(['vendor', 'departures.rooms'])->latest('approved_at')->orderByDesc('id')
            ->limit(max(0, $limit - $promoted->count()))->get()->map(fn (TravelPackage $item): array => $this->card($item));

        return [...$promoted->all(), ...$organic->all()];
    }

    /**
     * Whether the trip is promoted right now (the trip page label).
     */
    public function isSponsored(TravelPackage $package): bool
    {
        return in_array($package->id, $this->ranker->pick($this->query()->whereKey($package->id), countImpressions: false), true);
    }

    public function sponsoredLabel(): string
    {
        return $this->ranker->label();
    }

    /**
     * The trip card. `$sponsoredLabel` set = a promoted trip (D14).
     *
     * @return array<string, mixed>
     */
    public function card(TravelPackage $package, ?string $sponsoredLabel = null): array
    {
        $from = $package->departures->where('departs_on', '>', now())->flatMap->rooms->pluck('price_per_person')
            ->reduce(fn (?string $lowest, $price): string => $lowest === null || bccomp((string) $price, $lowest, 4) < 0 ? (string) $price : $lowest);

        return [
            'slug' => $package->slug,
            'title' => app()->getLocale() === 'ar' ? $package->title_ar : $package->title_en,
            'agency' => $package->vendor->store_name,
            'trip_type' => $package->trip_type,
            'destination' => $package->destination_city,
            'nights' => $package->nights,
            'from_price' => MoneyResource::make($from),
            'image' => PublicUrl::of($package->cover_path) === null ? null : ['url' => PublicUrl::of($package->cover_path), 'alt' => app()->getLocale() === 'ar' ? $package->title_ar : $package->title_en],
            'cover_url' => PublicUrl::of($package->cover_path),
            'status' => $package->status->value,
            'sponsored' => $sponsoredLabel !== null,
            'label' => $sponsoredLabel,
        ];
    }
}
