<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\CategoryAttribute;
use App\Domains\Catalog\Models\CategoryMeasurementField;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use Illuminate\Support\Facades\DB;

/**
 * Copies categories (each with its whole sub-tree, attributes and
 * measurement fields) from the current country to other countries (QA-011),
 * so the countries without a catalogue can list products. Categories are
 * matched by slug: existing ones are updated, never duplicated, so a copy
 * can be run again. Commission rates are copied only when asked
 * (they are commercial terms per country). Images keep the same path.
 */
class CategoryCopier
{
    public function __construct(private CountryContext $countryContext) {}

    /**
     * @param  array<int, int>  $categoryIds  roots to copy (with descendants)
     * @param  iterable<Country>  $targets
     * @return array<string, array{created: int, updated: int}> per country code
     */
    public function copy(array $categoryIds, iterable $targets, bool $withCommission = false): array
    {
        $source = $this->countryContext->current();
        $tree = $this->collect($categoryIds);
        $result = [];

        foreach ($targets as $target) {
            if ($target->is($source)) {
                continue;
            }

            $result[$target->code] = $this->countryContext->runAs($target, fn (): array => DB::connection((new Category)->getConnectionName())->transaction(
                fn (): array => $this->write($tree, $withCommission),
            ));
        }

        return $result;
    }

    /**
     * The categories in parent-first order with their attributes and fields
     * (read from the source country).
     *
     * @param  array<int, int>  $rootIds
     * @return array<int, array{category: array<string, mixed>, parent_slug: string|null, attributes: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>}>
     */
    private function collect(array $rootIds): array
    {
        $ordered = [];
        $frontier = Category::query()->whereIn('id', $rootIds)->get();
        $seen = [];

        for ($depth = 0; $frontier->isNotEmpty() && $depth < 10; $depth++) {
            foreach ($frontier as $category) {
                if (isset($seen[$category->id])) {
                    continue;
                }

                $seen[$category->id] = true;
                $ordered[] = [
                    'category' => $category->only(['slug', 'name_en', 'name_ar', 'description_en', 'description_ar', 'image_path', 'commission_rate', 'return_window_days', 'requires_contract', 'requires_certification', 'is_active', 'sort_order']),
                    'parent_slug' => $category->parent_id === null ? null : Category::query()->whereKey($category->parent_id)->value('slug'),
                    'attributes' => $category->attributeDefinitions()->get()->map(fn (CategoryAttribute $attribute): array => $attribute->only(['key', 'label_en', 'label_ar', 'type', 'options', 'is_required', 'is_filterable', 'sort_order']))->all(),
                    'fields' => CategoryMeasurementField::query()->where('category_id', $category->id)->get()
                        ->map(fn (CategoryMeasurementField $field): array => $field->only(['key', 'label_en', 'label_ar', 'unit', 'min_value', 'max_value', 'is_required', 'sort_order']))->all(),
                ];
            }

            $frontier = Category::query()->whereIn('parent_id', $frontier->pluck('id'))->get();
        }

        return $ordered;
    }

    /**
     * @param  array<int, array{category: array<string, mixed>, parent_slug: string|null, attributes: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>}>  $tree
     * @return array{created: int, updated: int}
     */
    private function write(array $tree, bool $withCommission): array
    {
        $created = 0;
        $updated = 0;

        foreach ($tree as $node) {
            $values = $node['category'];

            if (! $withCommission) {
                unset($values['commission_rate']);
            }

            $values['parent_id'] = $node['parent_slug'] === null ? null : Category::query()->where('slug', $node['parent_slug'])->value('id');
            $category = Category::query()->where('slug', $values['slug'])->first();

            if ($category === null) {
                $category = Category::query()->create($values);
                $created++;
            } else {
                $category->update($values);
                $updated++;
            }

            foreach ($node['attributes'] as $attribute) {
                CategoryAttribute::query()->updateOrCreate(['category_id' => $category->id, 'key' => $attribute['key']], $attribute);
            }

            foreach ($node['fields'] as $field) {
                CategoryMeasurementField::query()->updateOrCreate(['category_id' => $category->id, 'key' => $field['key']], $field);
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }
}
