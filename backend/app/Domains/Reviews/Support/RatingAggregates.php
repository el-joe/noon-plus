<?php

namespace App\Domains\Reviews\Support;

use App\Domains\Catalog\Models\Product;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rating summaries from approved reviews (lifecycle catalogue §2.9: the
 * aggregates move with every moderation decision). Products and vendors
 * keep a denormalised average + count for cards, sorting and the rating
 * facet; the histogram is computed on read.
 */
class RatingAggregates
{
    public function refresh(ProductReview $review): void
    {
        $this->refreshProduct((int) $review->product_id);

        if ($review->vendor_id !== null) {
            $this->refreshVendor((int) $review->vendor_id);
        }
    }

    public function refreshProduct(int $productId): void
    {
        [$average, $count] = $this->averageAndCount(ProductReview::query()->where('product_id', $productId));
        Product::query()->whereKey($productId)->update(['rating_average' => $average, 'rating_count' => $count]);
    }

    public function refreshVendor(int $vendorId): void
    {
        [$average, $count] = $this->averageAndCount(ProductReview::query()->where('vendor_id', $vendorId));
        Vendor::query()->whereKey($vendorId)->update(['rating' => $average, 'rating_count' => $count]);
    }

    /**
     * @param  Builder<ProductReview>  $reviews
     * @return array{average: string|null, count: int, histogram: array<int, int>}
     */
    public function summary(Builder $reviews): array
    {
        $counts = (clone $reviews)->whereIn('status', ReviewStatus::public())
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');
        $histogram = [];

        foreach ([5, 4, 3, 2, 1] as $stars) {
            $histogram[$stars] = (int) ($counts[$stars] ?? 0);
        }

        $count = array_sum($histogram);
        $sum = array_sum(array_map(fn (int $stars, int $total): int => $stars * $total, array_keys($histogram), $histogram));

        return [
            'average' => $count === 0 ? null : number_format(round($sum / $count, 2), 2, '.', ''),
            'count' => $count,
            'histogram' => $histogram,
        ];
    }

    /**
     * @param  Builder<ProductReview>  $reviews
     * @return array{0: string|null, 1: int}
     */
    private function averageAndCount(Builder $reviews): array
    {
        $summary = $this->summary($reviews);

        return [$summary['average'], $summary['count']];
    }
}
