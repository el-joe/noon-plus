<?php

namespace App\Domains\Content\Support;

use App\Domains\Catalog\Support\ListingCards;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Http\Resources\FlashSaleEventResource;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Domains\LiveMedia\Models\LiveStream;
use App\Domains\Travel\Support\TravelCatalog;
use App\Shared\Http\PublicCache;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Server-side data for published blocks (page-builder-blocks.md rule 2):
 * drops blocks an editor hid (`hidden`), limited to other languages
 * (`locales`) or outside their visibility window
 * (`settings.visibility.starts_at` / `ends_at`), resolves bilingual
 * settings (`{en, ar}`) for the request language ({@see BlockSettings::localize()}), resolves product sources
 * ({@see BlockProducts}: manual listing ids, new arrivals, category, brand,
 * bestsellers, top rated, deals, trending, recommended, coupon), category
 * tiles ({@see BlockTiles}), the flash sale of deal blocks (`flash_sale_id`
 * or `flash_sale_slug` → `data.sale`), which coupon codes of offer cards are
 * live (`data.codes`), live streams (and the live pill of a tile scroller)
 * and travel packages (promoted first, D14). Children of containers
 * (`panel_row` columns, `section_band` children) get their `data` too.
 * Every other block is passed through with its settings for the client to
 * render. Unknown types are skipped (rule 4).
 */
class BlockResolver
{
    public function __construct(private BlockProducts $products, private BlockTiles $tiles) {}

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $zones
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function resolve(array $zones, ?CarbonImmutable $at = null, bool $includeHidden = false): array
    {
        $at ??= CarbonImmutable::now();
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $resolved = [];

        foreach ($zones as $zone => $blocks) {
            $resolved[$zone] = [];

            foreach ($blocks as $block) {
                if (! BlockRegistry::has((string) ($block['type'] ?? '')) || (! $includeHidden && ! empty($block['hidden'])) || ! $this->inLocale((array) $block, $locale) || ! $this->visible((array) ($block['settings'] ?? []), $at)) {
                    continue;
                }

                $definition = BlockRegistry::get($block['type']);
                // Bilingual settings ({en, ar}) become plain values of the request language (AR ↔ EN fallback).
                $settings = BlockSettings::localize((array) ($block['settings'] ?? []), $locale);
                $resolved[$zone][] = [
                    'id' => $block['id'] ?? null,
                    'type' => $block['type'],
                    'kind' => $definition['kind'],
                    'settings' => $definition['kind'] === 'container' ? $this->children($settings, $at) : $settings,
                    'data' => $this->data($block['type'], $settings),
                ];
            }
        }

        return $resolved;
    }

    /**
     * Resolve the blocks inside a container: `columns[].block` (or the
     * column itself) and `children[]` get a `data` key; hidden ones go.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function children(array $settings, CarbonImmutable $at): array
    {
        foreach (['columns', 'children'] as $key) {
            if (! is_array($settings[$key] ?? null)) {
                continue;
            }

            $entries = [];

            foreach ($settings[$key] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $nested = is_array($entry['block'] ?? null);
                $child = $nested ? $entry['block'] : $entry;
                $type = (string) ($child['type'] ?? '');

                if ($type !== '' && BlockRegistry::has($type) && BlockRegistry::get($type)['kind'] !== 'container') {
                    if (! empty($child['hidden']) || ! empty($entry['hidden']) || ! $this->visible((array) ($child['settings'] ?? []), $at)) {
                        continue;
                    }

                    $child['data'] = $this->data($type, (array) ($child['settings'] ?? []));
                }

                $entries[] = $nested ? [...$entry, 'block' => $child] : $child;
            }

            $settings[$key] = $entries;
        }

        return $settings;
    }

    /**
     * Blocks limited to some languages (`locales: ["ar"]`) are left out of the others.
     *
     * @param  array<string, mixed>  $block
     */
    private function inLocale(array $block, string $locale): bool
    {
        $locales = $block['locales'] ?? null;

        return ! is_array($locales) || $locales === [] || in_array($locale, $locales, true);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function visible(array $settings, CarbonImmutable $at): bool
    {
        $visibility = (array) ($settings['visibility'] ?? []);

        try {
            if (! empty($visibility['starts_at']) && CarbonImmutable::parse($visibility['starts_at'])->greaterThan($at)) {
                return false;
            }

            if (! empty($visibility['ends_at']) && CarbonImmutable::parse($visibility['ends_at'])->lessThanOrEqualTo($at)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function data(string $type, array $settings): ?array
    {
        return match ($type) {
            'product_carousel', 'product_grid' => ['products' => $this->products->cards($settings)],
            'tile_scroller', 'category_tiles', 'category_showcase', 'tile_grid' => $this->tileData($type, $settings),
            'mega_deals', 'deal_grid', 'frenzy_deals', 'hero_offer_list' => $this->saleData($settings),
            'offer_cards' => $this->offerCodes($settings),
            'category_promo_tiles', 'category_brand_tiles', 'category_gift_band', 'category_deal_columns' => app(CategoryBlocks::class)->data($type, $settings),
            // D14: promoted trips first, labelled (the same list as `travel/packages`).
            'travel_packages_carousel' => ['packages' => array_slice(app(TravelCatalog::class)->page(array_filter([
                'trip_type' => is_string($settings['trip_type'] ?? null) ? $settings['trip_type'] : null,
                'destination' => is_string($settings['destination'] ?? null) ? $settings['destination'] : null,
            ]))['items'], 0, min(24, max(1, (int) ($settings['limit'] ?? 12))))],
            'live_streams_strip' => ['streams' => LiveStream::query()->whereIn('status', [StreamStatus::Live, StreamStatus::Scheduled])
                ->orderByRaw("case status when 'live' then 0 else 1 end")->orderBy('scheduled_at')->limit(12)->get()
                ->map(fn (LiveStream $stream): array => ['slug' => $stream->slug, 'title' => $stream->title(), 'status' => $stream->status->value, 'type' => $stream->source->value, 'scheduled_at' => $stream->scheduled_at?->toIso8601String()])->all()],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function tileData(string $type, array $settings): ?array
    {
        $tiles = $this->tiles->tiles($type, $settings);
        $data = $tiles === null ? [] : ['tiles' => $tiles];

        if ($type === 'tile_scroller' && filter_var($settings['live_pill'] ?? false, FILTER_VALIDATE_BOOL)) {
            $stream = LiveStream::query()->where('status', StreamStatus::Live)->orderByDesc('started_at')->orderByDesc('id')->first();
            $data['live'] = $stream === null ? null : ['slug' => $stream->slug, 'title' => $stream->title()];
        }

        return $data === [] ? null : $data;
    }

    /**
     * The running (or upcoming, published) flash sale of a deal block.
     *
     * @param  array<string, mixed>  $settings
     * @return array{sale: array<string, mixed>}|null
     */
    private function saleData(array $settings): ?array
    {
        $id = (int) ($settings['flash_sale_id'] ?? 0);
        $slug = is_string($settings['flash_sale_slug'] ?? null) ? $settings['flash_sale_slug'] : null;

        if ($id <= 0 && ($slug === null || $slug === '')) {
            return null;
        }

        return PublicCache::remember(PublicCache::countryGroup(BlockProducts::CACHE_GROUP), app()->getLocale().':sale:'.$id.':'.$slug, 60, function () use ($id, $slug): ?array {
            $event = FlashSaleEvent::query()
                ->whereIn('status', FlashSaleEventStatus::pricing())
                ->where('ends_at', '>', now())
                ->when($id > 0, fn ($query) => $query->whereKey($id), fn ($query) => $query->where('slug', $slug))
                ->with(['submissions' => fn ($query) => $query
                    ->whereIn('status', [...FlashSaleSubmissionStatus::pricing(), FlashSaleSubmissionStatus::SoldOut])
                    ->orderBy('flash_price')
                    ->orderBy('id')
                    ->with(ListingCards::relations('listing.'))])
                ->first();

            // Plain arrays only: the cache refuses to unserialize objects (cache.serializable_classes = false).
            return $event === null ? null : ['sale' => json_decode((string) json_encode((new FlashSaleEventResource($event))->resolve(request())), true)];
        });
    }

    /**
     * Which of the offer cards' coupon codes are live right now.
     *
     * @param  array<string, mixed>  $settings
     * @return array{codes: array<int, string>}|null
     */
    private function offerCodes(array $settings): ?array
    {
        $codes = collect((array) ($settings['items'] ?? []))->filter(fn ($item): bool => is_array($item))->pluck('code')->filter(fn ($code): bool => is_string($code) && $code !== '')
            ->map(fn (string $code): string => strtoupper($code))->unique()->values();

        if ($codes->isEmpty()) {
            return null;
        }

        return ['codes' => Coupon::query()->whereIn('code', $codes->all())->where('is_active', true)->whereNull('admin_disabled_at')
            ->where(fn ($live) => $live->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($live) => $live->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('code')->map(fn ($code): string => strtoupper((string) $code))->values()->all()];
    }
}
