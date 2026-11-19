<?php

namespace App\Domains\Advertising\Support;

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Models\AdSlot;
use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Shared\Settings\VerticalSettings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which promoted trips lead a travel list (D14). Eligible = a promotion
 * that is `active` and inside its paid window, for a package that matches
 * the list's filters and is itself active (published, bookable) with an
 * active agency — an inactive or unpublished trip is never promoted. One
 * entry per package. At most the travel slot's `max_concurrent` lead the
 * list; when more are live than that, the set rotates fairly: the live
 * promotions in a fixed order, a window of `max_concurrent` that moves
 * one step every `travel.promotion_rotation_minutes`, wrapping around, so
 * each gets the same share of time at the top. Every served promotion
 * counts an impression. Items carry `sponsored: true` and the localized
 * label (`travel.promotion_label_en|ar`).
 */
class TravelSponsoredRanker
{
    public function __construct(private VerticalSettings $settings) {}

    /**
     * Ids of the promoted packages to show first, in order.
     *
     * @param  Builder<TravelPackage>  $listQuery  the list's filtered package query
     * @return array<int, int>
     */
    public function pick(Builder $listQuery, ?int $cap = null, bool $countImpressions = true): array
    {
        $slot = AdSlot::query()->where('code', TravelPromotions::SLOT_CODE)->first();

        if ($slot === null || ! $slot->is_active || $slot->max_concurrent < 1) {
            return [];
        }

        $cap = min($cap ?? $slot->max_concurrent, $slot->max_concurrent);
        $eligible = (clone $listQuery)->where('travel_packages.status', PackageStatus::Active)
            ->whereHas('vendor', fn ($vendor) => $vendor->where('status', 'active'))
            ->select('travel_packages.id');

        $live = TravelPromotion::query()
            ->where('status', TravelPromotionStatus::Active)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->whereIn('travel_package_id', $eligible->toBase())
            ->orderBy('id')
            ->get(['id', 'travel_package_id'])
            ->unique('travel_package_id')
            ->values();

        if ($live->isEmpty()) {
            return [];
        }

        $count = $live->count();
        $chosen = $live;

        if ($count > $cap) {
            $windowSeconds = max(1, $this->settings->int('travel.promotion_rotation_minutes')) * 60;
            $offset = intdiv(now()->getTimestamp(), $windowSeconds) % $count;
            $chosen = collect(range(0, $cap - 1))->map(fn (int $step) => $live[($offset + $step) % $count]);
        }

        if ($countImpressions) {
            TravelPromotion::query()->whereKey($chosen->pluck('id')->all())->increment('impressions');
        }

        return $chosen->pluck('travel_package_id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    public function label(): string
    {
        return app()->getLocale() === 'ar' ? $this->settings->string('travel.promotion_label_ar') : $this->settings->string('travel.promotion_label_en');
    }
}
