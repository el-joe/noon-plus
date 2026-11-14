<?php

namespace App\Domains\Warranty\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Warranty\Models\WarrantyPlan;
use Illuminate\Support\Collection;

/**
 * Which warranty plans a product can be sold with: active plans of its own
 * category and of the parent category (plans are scoped to parent
 * categories, SRS §4.1.2).
 */
class WarrantyPlanCatalog
{
    /**
     * @param  array<int, int>  $categoryIds
     * @return Collection<int, Collection<int, WarrantyPlan>> category id => plans
     */
    public function forCategories(array $categoryIds): Collection
    {
        $categoryIds = array_values(array_unique($categoryIds));

        if ($categoryIds === []) {
            return collect();
        }

        $parents = Category::query()->whereKey($categoryIds)->pluck('parent_id', 'id');
        $plans = WarrantyPlan::query()
            ->where('is_active', true)
            ->whereIn('category_id', array_filter([...$categoryIds, ...$parents->values()->all()]))
            ->orderBy('sort_order')
            ->orderBy('duration_months')
            ->orderBy('id')
            ->get();

        return collect($categoryIds)->mapWithKeys(fn (int $categoryId): array => [
            $categoryId => $plans->filter(fn (WarrantyPlan $plan): bool => in_array((int) $plan->category_id, [$categoryId, (int) $parents->get($categoryId)], true))->values(),
        ]);
    }

    public function isAvailableFor(WarrantyPlan $plan, int $categoryId): bool
    {
        return $this->forCategories([$categoryId])->get($categoryId, collect())->contains('id', $plan->id);
    }
}
