<?php

namespace App\Domains\Catalog\Http\Requests;

use App\Domains\Catalog\Support\ProductSearch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Product comparison (FR-CAT-6): 2 to 4 product slugs, comma separated.
 */
class ProductCompareRequest extends FormRequest
{
    public const MAX_PRODUCTS = 4;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['slugs' => ['required', 'string', 'max:1100']];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $count = count(array_unique(ProductSearch::slugs($this->input('slugs'))));

            if ($count < 2 || $count > self::MAX_PRODUCTS) {
                $validator->errors()->add('slugs', __('Compare 2 to :max products.', ['max' => self::MAX_PRODUCTS]));
            }
        }];
    }

    /**
     * @return array<int, string>
     */
    public function slugs(): array
    {
        return array_values(array_unique(ProductSearch::slugs($this->validated('slugs'))));
    }
}
