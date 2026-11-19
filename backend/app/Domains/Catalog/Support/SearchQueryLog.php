<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\SearchQueryStat;
use App\Shared\Http\LikeSearch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Search analytics for discovery (QC-043, G-A1): every storefront search
 * adds one hit to its normalised query for today — counts only, nothing
 * about who searched. Popular searches are the most searched queries that
 * found products; trending ones grew most in the last days against the
 * days before.
 */
class SearchQueryLog
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    /**
     * Lower case, single spaces, trimmed; null when too short to count.
     */
    public static function normalise(?string $query): ?string
    {
        $normalised = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $query)));
        $normalised = mb_substr($normalised, 0, self::MAX_LENGTH);

        return mb_strlen($normalised) < self::MIN_LENGTH ? null : $normalised;
    }

    public function record(?string $query, int $resultsCount): void
    {
        $normalised = self::normalise($query);

        if ($normalised === null) {
            return;
        }

        $stat = SearchQueryStat::query()->firstOrCreate(['day' => now()->toDateString(), 'query' => $normalised]);
        $stat->newQuery()->whereKey($stat->id)->update([
            'hits' => DB::raw('hits + 1'),
            'results_count' => $resultsCount,
            'updated_at' => now(),
        ]);
    }

    /**
     * The most searched queries of the window that found products.
     *
     * @return Collection<int, array{query: string, hits: int}>
     */
    public function popular(int $limit = 10, ?string $containing = null): Collection
    {
        $minHits = (int) config('nawy.discovery.popular_min_hits');

        return SearchQueryStat::query()
            ->where('day', '>=', now()->subDays((int) config('nawy.discovery.popular_window_days'))->toDateString())
            ->when($containing !== null, fn ($query) => $query->where('query', 'like', LikeSearch::contains($containing)))
            ->groupBy('query')
            ->havingRaw('sum(hits) >= ?', [$minHits])
            ->havingRaw('max(results_count) > 0')
            ->orderByRaw('sum(hits) desc')
            ->orderBy('query')
            ->limit($limit)
            ->selectRaw('query, sum(hits) as total_hits')
            ->get()
            ->map(fn ($row): array => ['query' => (string) $row->query, 'hits' => (int) $row->total_hits]);
    }

    /**
     * Queries whose searches per day in the recent days grew most against
     * the baseline days before them (a new query counts from zero).
     *
     * @return Collection<int, array{query: string, hits: int, growth: float}>
     */
    public function trending(int $limit = 10): Collection
    {
        $recentDays = max(1, (int) config('nawy.discovery.trending_recent_days'));
        $baselineDays = max(1, (int) config('nawy.discovery.trending_baseline_days'));
        $recentFrom = now()->subDays($recentDays - 1)->toDateString();
        $baselineFrom = now()->subDays($recentDays + $baselineDays - 1)->toDateString();
        $minHits = (int) config('nawy.discovery.popular_min_hits');

        $rows = SearchQueryStat::query()
            ->where('day', '>=', $baselineFrom)
            ->groupBy('query')
            ->selectRaw('query, sum(case when day >= ? then hits else 0 end) as recent_hits, sum(case when day < ? then hits else 0 end) as baseline_hits, max(results_count) as results', [$recentFrom, $recentFrom])
            ->get();

        return $rows
            ->filter(fn ($row): bool => (int) $row->recent_hits >= $minHits && (int) $row->results > 0)
            ->map(function ($row) use ($recentDays, $baselineDays): array {
                $recentPerDay = (int) $row->recent_hits / $recentDays;
                $baselinePerDay = (int) $row->baseline_hits / $baselineDays;

                return ['query' => (string) $row->query, 'hits' => (int) $row->recent_hits, 'growth' => round($recentPerDay / ($baselinePerDay + 1), 2)];
            })
            ->filter(fn (array $row): bool => $row['growth'] >= 1)
            ->sortBy([['growth', 'desc'], ['hits', 'desc'], ['query', 'asc']])
            ->take($limit)
            ->values();
    }
}
