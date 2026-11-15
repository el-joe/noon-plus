<?php

namespace App\Domains\Finance\Support;

use App\Domains\Catalog\Models\Category;

/**
 * Category commission percent (settings catalogue `category.commission_percent`):
 * the category's own rate, else its parent's, else the country default
 * (config `nawy.finance.default_commission_percent`, 10 — owner review).
 * Snapshotted on each order item at placement.
 */
class CommissionRates
{
    public function __construct(private FinanceSettings $settings) {}

    /**
     * @param  array<int, int>  $categoryIds
     * @return array<int, string> category id => percent
     */
    public function forCategories(array $categoryIds): array
    {
        $default = (string) $this->settings->get('category.commission_percent', 'default_commission_percent');
        $categories = Category::query()->whereKey(array_unique($categoryIds))->with('parent')->get()->keyBy('id');

        return collect(array_unique($categoryIds))->mapWithKeys(fn (int $id): array => [
            $id => (string) ($categories->get($id)?->commission_rate ?? $categories->get($id)?->parent?->commission_rate ?? $default),
        ])->all();
    }
}
