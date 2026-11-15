<?php

namespace App\Domains\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Product listing / facet filters (G-W4). `brand` and `seller` take one
 * slug or several comma separated; `attr[colour]=Red,Blue` filters on
 * variant options; `rating` is the minimum average stars.
 */
class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:1000'],
            'seller' => ['nullable', 'string', 'max:1000'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'attr' => ['nullable', 'array', 'max:10'],
            'attr.*' => ['string', 'max:500'],
            'sort' => ['nullable', 'in:recommended,price_asc,price_desc,newest,rating'],
            'per_page' => ['nullable', 'integer', 'between:1,60'],
        ];
    }
}
