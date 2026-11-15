<?php

namespace App\Domains\Content\Support;

use App\Domains\Catalog\Support\ListingCards;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Domains\LiveMedia\Models\LiveStream;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Server-side data for published blocks (page-builder-blocks.md rule 2):
 * drops blocks outside their visibility window, resolves product sources
 * (manual listing ids, new arrivals) and live streams; every other block is
 * passed through with its settings for the client to render. Unknown types
 * are skipped (rule 4).
 */
class BlockResolver
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $zones
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function resolve(array $zones, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $resolved = [];

        foreach ($zones as $zone => $blocks) {
            $resolved[$zone] = [];

            foreach ($blocks as $block) {
                if (! BlockRegistry::has((string) ($block['type'] ?? '')) || ! $this->visible((array) ($block['settings'] ?? []), $at)) {
                    continue;
                }

                $definition = BlockRegistry::get($block['type']);
                $resolved[$zone][] = [
                    'id' => $block['id'] ?? null,
                    'type' => $block['type'],
                    'kind' => $definition['kind'],
                    'settings' => $block['settings'] ?? [],
                    'data' => $this->data($block['type'], (array) ($block['settings'] ?? [])),
                ];
            }
        }

        return $resolved;
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
            'product_carousel', 'product_grid' => ['products' => $this->products($settings)],
            'live_streams_strip' => ['streams' => LiveStream::query()->whereIn('status', [StreamStatus::Live, StreamStatus::Scheduled])
                ->orderByRaw("case status when 'live' then 0 else 1 end")->orderBy('scheduled_at')->limit(12)->get()
                ->map(fn (LiveStream $stream): array => ['slug' => $stream->slug, 'title' => $stream->title(), 'status' => $stream->status->value, 'type' => $stream->source->value, 'scheduled_at' => $stream->scheduled_at?->toIso8601String()])->all()],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>
     */
    private function products(array $settings): array
    {
        $limit = min(48, max(1, (int) ($settings['max_items'] ?? 12)));
        $query = Listing::query()->where('status', ListingStatus::Active)->with(ListingCards::relations());

        $listings = match ($settings['source'] ?? null) {
            'manual' => $query->whereKey(array_map('intval', (array) ($settings['listing_ids'] ?? [])))->limit($limit)->get()
                ->sortBy(fn (Listing $listing): int|false => array_search($listing->id, array_map('intval', (array) ($settings['listing_ids'] ?? [])), true))->values(),
            'new_arrivals' => $query->latest('id')->limit($limit)->get(),
            default => collect(),
        };

        return $listings->map(fn (Listing $listing): array => ListingCards::card($listing))->all();
    }
}
