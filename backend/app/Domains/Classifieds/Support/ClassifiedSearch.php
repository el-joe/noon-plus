<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Public classified search (MP v2 §14b, FR-CLS-5): live ads only, by
 * category (section with descendants), vertical (inherited, G-W21), text, kind (offer / wanted), city,
 * price, dynamic attribute filters (`attr[key]=v`, `attr[key][]=v`,
 * `attr[key][min|max]`), map bounds, verified shops, with video, property
 * fields; featured ads first, then the chosen sort (recommended = latest
 * bump / boost, newest, price).
 */
class ClassifiedSearch
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ClassifiedAd>
     */
    public function query(array $filters): Builder
    {
        $query = ClassifiedAd::query()->where('classified_ads.status', ClassifiedAdStatus::Active)->with(['media', 'category', 'vendor', 'property']);

        if (! empty($filters['category'])) {
            $category = ClassifiedCategory::query()->where('slug', $filters['category'])->first();
            $query->whereIn('classified_category_id', $category === null ? [0] : $category->descendantIds());
        }

        if (! empty($filters['vertical'])) {
            $query->whereIn('classified_category_id', ClassifiedCategory::idsForVertical((string) $filters['vertical']) ?: [0]);
        }

        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']).'%';
            $query->where(fn (Builder $search) => $search->where('title', 'like', $term)->orWhere('description', 'like', $term));
        }

        foreach (['kind', 'city'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (! empty($filters['verified'])) {
            $query->whereHas('vendor', fn (Builder $vendor) => $vendor->whereNotNull('verified_at'));
        }

        if (! empty($filters['with_video'])) {
            $query->whereHas('media', fn (Builder $media) => $media->where('type', 'video'));
        }

        if (! empty($filters['bounds'])) {
            [$south, $west, $north, $east] = array_map('floatval', explode(',', (string) $filters['bounds']) + [0, 0, 0, 0]);
            $query->whereBetween('latitude', [min($south, $north), max($south, $north)])
                ->whereBetween('longitude', [min($west, $east), max($west, $east)]);
        }

        foreach (['purpose', 'property_type'] as $column) {
            if (! empty($filters[$column])) {
                $query->whereHas('property', fn (Builder $property) => $property->where($column, $filters[$column]));
            }
        }

        if (isset($filters['bedrooms_min'])) {
            $query->whereHas('property', fn (Builder $property) => $property->where('bedrooms', '>=', (int) $filters['bedrooms_min']));
        }

        if (isset($filters['bathrooms_min'])) {
            $query->whereHas('property', fn (Builder $property) => $property->where('bathrooms', '>=', (int) $filters['bathrooms_min']));
        }

        if (isset($filters['min_area'])) {
            $query->whereHas('property', fn (Builder $property) => $property->where('area_sqm', '>=', $filters['min_area']));
        }

        if (isset($filters['max_area'])) {
            $query->whereHas('property', fn (Builder $property) => $property->where('area_sqm', '<=', $filters['max_area']));
        }

        foreach ((array) ($filters['attr'] ?? []) as $key => $condition) {
            $this->attributeFilter($query, (string) $key, $condition);
        }

        $query->orderByRaw('CASE WHEN featured_until IS NOT NULL AND featured_until > ? THEN 0 ELSE 1 END', [now()]);

        match ($filters['sort'] ?? 'recommended') {
            'newest' => $query->orderByDesc('approved_at'),
            'price_asc' => $query->orderByRaw('price IS NULL')->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            default => $query->orderByRaw('CASE WHEN boosted_until IS NOT NULL AND boosted_until > ? THEN 0 ELSE 1 END', [now()])->orderByDesc('bumped_at'),
        };

        return $query->orderByDesc('classified_ads.id');
    }

    /**
     * @param  Builder<ClassifiedAd>  $query
     */
    private function attributeFilter(Builder $query, string $key, mixed $condition): void
    {
        $key = mb_substr($key, 0, 48);
        $exists = fn (callable $where) => $query->whereExists(function (QueryBuilder $values) use ($key, $where): void {
            $values->selectRaw('1')->from('classified_ad_values')
                ->whereColumn('classified_ad_values.classified_ad_id', 'classified_ads.id')
                ->where('classified_ad_values.key', $key);
            $where($values);
        });

        if (is_array($condition) && (array_key_exists('min', $condition) || array_key_exists('max', $condition))) {
            $exists(function (QueryBuilder $values) use ($condition): void {
                if (isset($condition['min']) && is_numeric($condition['min'])) {
                    $values->where('value_number', '>=', $condition['min']);
                }

                if (isset($condition['max']) && is_numeric($condition['max'])) {
                    $values->where('value_number', '<=', $condition['max']);
                }
            });

            return;
        }

        $options = array_values(array_filter(array_map('strval', (array) $condition), fn (string $value): bool => $value !== ''));

        if ($options !== []) {
            $numbers = array_values(array_filter($options, 'is_numeric'));
            $exists(fn (QueryBuilder $values) => $values->where(fn (QueryBuilder $match) => $match->whereIn('value_string', $options)
                ->when($numbers !== [], fn (QueryBuilder $numeric) => $numeric->orWhereIn('value_number', $numbers))));
        }
    }
}
