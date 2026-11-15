<?php

namespace App\Domains\Advertising\Support;

use App\Domains\Advertising\Enums\AdCampaignStatus;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Support\BuyBox;
use App\Shared\Settings\VerticalSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * SponsoredRanker (MP §3, SRS §10, settings `sponsored_ranking.*`).
 *
 * Eligible = active campaign inside its dates, its listing live and its
 * product active with a sellable offer, and targeting that matches the
 * page: keywords against the search text, categories against the browsed
 * category (or the product's own category), or automatic (no targeting:
 * same category, or the search text in the product title). Ranked by eCPM
 * (CPM bid; CPC bid × 1000 × smoothed CTR), one ad per product. The page
 * gets at most `max_sponsored_per_page` per 48 results (pro rata, at least
 * one), placed at the 1-based `sponsored_slots`; later pages show the next
 * ads. A sponsored product is removed from its organic position on the
 * page (no duplicates) and every sponsored item carries the "Ad" label.
 * Each served ad records an impression (CPM billing / CTR).
 */
class SponsoredRanker
{
    public function __construct(private AdCampaigns $campaigns, private VerticalSettings $settings, private BuyBox $buyBox) {}

    /**
     * @param  Collection<int, Product>  $organic  the organic page
     * @param  array{q?: string|null, category?: string|null, page?: int, per_page?: int}  $context
     * @return array<int, array{product: Product, sponsored: bool, label: string|null, ad_token: string|null}>
     */
    public function interleave(Collection $organic, array $context, ?string $visitorKey = null): array
    {
        $perPage = max(1, (int) ($context['per_page'] ?? 24));
        $page = max(1, (int) ($context['page'] ?? 1));
        $slots = array_values(array_map('intval', (array) $this->settings->get('advertising.sponsored_slots')));
        $limit = min(count($slots), max(1, (int) ceil($perPage * $this->settings->int('advertising.max_sponsored_per_page') / 48)));
        $placement = ! empty($context['q']) ? 'search' : 'category';

        $ranked = $this->ranked($context);
        $ads = $ranked->slice(($page - 1) * $limit, $limit)->values();

        if ($ads->isEmpty() && $page > 1) {
            $ads = $ranked->take($limit)->values();
        }

        $sponsoredIds = $ads->map(fn (array $ad): int => $ad['product']->id)->all();
        $items = $organic->reject(fn (Product $product): bool => in_array($product->id, $sponsoredIds, true))
            ->map(fn (Product $product): array => ['product' => $product, 'sponsored' => false, 'label' => null, 'ad_token' => null])
            ->values()->all();
        $label = app()->getLocale() === 'ar' ? $this->settings->string('advertising.label_ar') : $this->settings->string('advertising.label_en');

        foreach ($ads as $index => $ad) {
            $position = min($slots[$index] - 1, count($items));
            array_splice($items, $position, 0, [[
                'product' => $ad['product'],
                'sponsored' => true,
                'label' => $label,
                'ad_token' => Crypt::encryptString(json_encode(['c' => $ad['campaign']->id, 'p' => $ad['product']->slug, 'pl' => $placement])),
            ]]);
            $this->campaigns->impression($ad['campaign'], $placement, $visitorKey);
        }

        return $items;
    }

    /**
     * Eligible campaigns for the page context, best eCPM first, one per product.
     *
     * @param  array{q?: string|null, category?: string|null}  $context
     * @return Collection<int, array{campaign: AdCampaign, product: Product, ecpm: float}>
     */
    public function ranked(array $context): Collection
    {
        $term = mb_strtolower(trim((string) ($context['q'] ?? '')));
        $category = empty($context['category']) ? null : Category::query()->where('slug', $context['category'])->first();
        $pageCategoryIds = $category === null ? [] : array_merge([(int) $category->id], $category->children()->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $priorClicks = $this->settings->int('advertising.ctr_prior_clicks');
        $priorImpressions = max(1, $this->settings->int('advertising.ctr_prior_impressions'));

        return AdCampaign::query()
            ->where('status', AdCampaignStatus::Active)
            ->whereHas('listing', fn ($listing) => $listing->where('status', ListingStatus::Active))
            ->with('listing.variant.product.brand', 'listing.variant.product.category')
            ->get()
            ->filter(fn (AdCampaign $campaign): bool => $this->campaigns->isServable($campaign) && $campaign->listing->variant->product->status === 'active')
            ->filter(fn (AdCampaign $campaign): bool => $this->matches($campaign, $term, $pageCategoryIds))
            ->map(function (AdCampaign $campaign) use ($priorClicks, $priorImpressions): array {
                $ctr = ($campaign->clicks + $priorClicks) / ($campaign->impressions + $priorImpressions);
                $ecpm = $campaign->pricing_model === 'cpm' ? (float) $campaign->bid : (float) $campaign->bid * 1000 * $ctr;

                return ['campaign' => $campaign, 'product' => $campaign->listing->variant->product, 'ecpm' => $ecpm];
            })
            ->sort(fn (array $a, array $b): int => [$b['ecpm'], $a['campaign']->id] <=> [$a['ecpm'], $b['campaign']->id])
            ->unique(fn (array $ad): int => $ad['product']->id)
            ->filter(fn (array $ad): bool => $this->hasSellableOffer($ad['product']))
            ->values()
            ->map(function (array $ad): array {
                $ad['product']->setAttribute('best_price', $this->buyBox->offersFor($ad['product'])->first()?->effectivePrice());

                return $ad;
            });
    }

    /**
     * @param  array<int, int>  $pageCategoryIds
     */
    private function matches(AdCampaign $campaign, string $term, array $pageCategoryIds): bool
    {
        $product = $campaign->listing->variant->product;
        $keywords = $campaign->keywords ?? [];
        $categories = $campaign->category_ids ?? [];

        if ($keywords === [] && $categories === []) {
            return ($pageCategoryIds !== [] && in_array((int) $product->category_id, $pageCategoryIds, true))
                || ($term !== '' && str_contains(mb_strtolower($product->title_en.' '.$product->title_ar), $term));
        }

        $keywordHit = $term !== '' && collect($keywords)->contains(fn (string $keyword): bool => str_contains($term, $keyword) || str_contains($keyword, $term));
        $categoryHit = $pageCategoryIds !== [] && array_intersect($categories, $pageCategoryIds) !== [];

        return $keywordHit || $categoryHit;
    }

    private function hasSellableOffer(Product $product): bool
    {
        return $this->buyBox->offersFor($product)->isNotEmpty();
    }
}
