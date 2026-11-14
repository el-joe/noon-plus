<?php

namespace App\Domains\Orders\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Countries\Models\Country;
use App\Domains\Settings\SettingsService;

/**
 * Return window in days per category (settings catalogue `return_window_days`):
 * the category's own value, else its parent's, else the country setting
 * (default 14). Snapshotted on each order item at placement.
 */
class ReturnWindow
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @param  array<int, int>  $categoryIds
     * @return array<int, int> category id => days
     */
    public function daysForCategories(Country $country, array $categoryIds): array
    {
        $default = $this->countryDefault($country);
        $categories = Category::query()->whereKey(array_unique($categoryIds))->with('parent')->get()->keyBy('id');

        return collect(array_unique($categoryIds))->mapWithKeys(fn (int $id): array => [
            $id => (int) ($categories->get($id)?->return_window_days ?? $categories->get($id)?->parent?->return_window_days ?? $default),
        ])->all();
    }

    public function countryDefault(Country $country): int
    {
        return (int) $this->settings->get('return_window_days', config('nawy.orders.return_window_days'), $country);
    }
}
