<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\CategoryMeasurementField;
use App\Domains\Catalog\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Made-to-order measurements (MP v2 §14b, F13): the measurement fields of
 * a product's category (inherited from parents; the nearest definition of a
 * key wins) are asked on the cart line, validated against their range,
 * copied to the order item and shown to the seller.
 */
class Measurements
{
    /**
     * @return Collection<int, CategoryMeasurementField>
     */
    public function fieldsFor(?Category $category): Collection
    {
        if ($category === null) {
            return collect();
        }

        $fields = collect();

        foreach (array_reverse($category->lineage()) as $ancestor) {
            foreach (CategoryMeasurementField::query()->where('category_id', $ancestor->id)->orderBy('sort_order')->orderBy('id')->get() as $field) {
                $fields->put($field->key, $field);
            }
        }

        return $fields->values();
    }

    public function requiredFor(Product $product): bool
    {
        return $product->fulfilment_mode === 'made_to_order' && $this->fieldsFor($product->category)->isNotEmpty();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, float>
     *
     * @throws ValidationException
     */
    public function validate(Product $product, array $input, bool $requireAll = true): array
    {
        $errors = [];
        $clean = [];

        foreach ($this->fieldsFor($product->category) as $field) {
            $value = $input[$field->key] ?? null;
            $name = "measurements.{$field->key}";

            if ($value === null || $value === '') {
                if ($requireAll && $field->is_required) {
                    $errors[$name][] = __(':label is required.', ['label' => $field->label_en]);
                }

                continue;
            }

            if (! is_numeric($value)
                || ($field->min_value !== null && (float) $value < (float) $field->min_value)
                || ($field->max_value !== null && (float) $value > (float) $field->max_value)) {
                $errors[$name][] = __(':label must be between :min and :max :unit.', ['label' => $field->label_en, 'min' => (float) $field->min_value, 'max' => (float) $field->max_value, 'unit' => $field->unit]);

                continue;
            }

            $clean[$field->key] = round((float) $value, 1);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * @return array<int, array{key: string, label: string, unit: string, min: string|null, max: string|null, required: bool}>
     */
    public function describe(Product $product): array
    {
        return $this->fieldsFor($product->category)->map(fn (CategoryMeasurementField $field): array => [
            'key' => $field->key,
            'label' => app()->getLocale() === 'ar' ? $field->label_ar : $field->label_en,
            'unit' => $field->unit,
            'min' => $field->min_value,
            'max' => $field->max_value,
            'required' => $field->is_required,
        ])->values()->all();
    }
}
