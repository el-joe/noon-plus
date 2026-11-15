<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Enums\AttributeType;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedAttribute;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Validates an ad's dynamic attribute values against the category schema
 * (own + inherited fields) and writes the indexed rows used by the search
 * filters. Unknown keys are dropped; `$strict` also enforces required fields
 * (at submission — drafts may be incomplete).
 */
class ClassifiedSpecs
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function normalize(ClassifiedCategory $category, array $input, bool $strict): array
    {
        $errors = [];
        $specs = [];

        foreach ($category->attributeSchema() as $attribute) {
            $value = $input[$attribute->key] ?? null;
            $field = "specs.{$attribute->key}";

            if ($value === null || $value === '' || $value === []) {
                if ($strict && $attribute->is_required) {
                    $errors[$field][] = __(':label is required.', ['label' => $attribute->label_en]);
                }

                continue;
            }

            $error = $this->check($attribute, $value);

            if ($error !== null) {
                $errors[$field][] = $error;

                continue;
            }

            $specs[$attribute->key] = $this->cast($attribute, $value);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $specs;
    }

    /**
     * Rewrite the indexed values of an ad (one row per multi-select value).
     */
    public function index(ClassifiedAd $ad): void
    {
        $ad->values()->delete();
        $schema = $ad->category->attributeSchema()->keyBy('key');

        foreach ($ad->specs ?? [] as $key => $value) {
            $attribute = $schema->get($key);

            if ($attribute === null) {
                continue;
            }

            foreach ((array) $value as $single) {
                $ad->values()->create($attribute->type->isNumeric()
                    ? ['key' => $key, 'value_number' => (string) $single]
                    : ['key' => $key, 'value_string' => is_bool($single) ? ($single ? '1' : '0') : mb_substr((string) $single, 0, 255)]);
            }
        }
    }

    /**
     * Allowed option values of a select field (options are strings or {value, label_en, label_ar}).
     *
     * @return array<int, string>
     */
    public function optionValues(ClassifiedAttribute $attribute): array
    {
        return array_map(fn ($option): string => (string) (is_array($option) ? ($option['value'] ?? '') : $option), $attribute->options ?? []);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function describe(ClassifiedCategory $category): Collection
    {
        return $category->attributeSchema()->map(fn (ClassifiedAttribute $attribute): array => [
            'key' => $attribute->key,
            'label' => app()->getLocale() === 'ar' ? $attribute->label_ar : $attribute->label_en,
            'type' => $attribute->type->value,
            'options' => $attribute->options ?? [],
            'unit' => $attribute->unit,
            'min' => $attribute->min_value,
            'max' => $attribute->max_value,
            'required' => $attribute->is_required,
            'filterable' => $attribute->is_filterable,
        ]);
    }

    private function check(ClassifiedAttribute $attribute, mixed $value): ?string
    {
        $label = $attribute->label_en;

        return match ($attribute->type) {
            AttributeType::Number, AttributeType::Year => match (true) {
                ! is_numeric($value) => __(':label must be a number.', ['label' => $label]),
                $attribute->min_value !== null && bccomp((string) $value, (string) $attribute->min_value, 4) < 0 => __(':label must be at least :min.', ['label' => $label, 'min' => (float) $attribute->min_value]),
                $attribute->max_value !== null && bccomp((string) $value, (string) $attribute->max_value, 4) > 0 => __(':label must be at most :max.', ['label' => $label, 'max' => (float) $attribute->max_value]),
                default => null,
            },
            AttributeType::Select => in_array((string) $value, $this->optionValues($attribute), true) ? null : __('Choose a valid :label.', ['label' => $label]),
            AttributeType::MultiSelect => is_array($value) && array_diff(array_map('strval', $value), $this->optionValues($attribute)) === [] ? null : __('Choose valid :label values.', ['label' => $label]),
            AttributeType::Boolean => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true) ? null : __(':label must be yes or no.', ['label' => $label]),
            AttributeType::Text => is_scalar($value) && mb_strlen((string) $value) <= 255 ? null : __(':label is too long.', ['label' => $label]),
        };
    }

    private function cast(ClassifiedAttribute $attribute, mixed $value): mixed
    {
        return match ($attribute->type) {
            AttributeType::Number => is_numeric($value) && str_contains((string) $value, '.') ? (float) $value : (int) $value,
            AttributeType::Year => (int) $value,
            AttributeType::MultiSelect => array_values(array_map('strval', $value)),
            AttributeType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }
}
