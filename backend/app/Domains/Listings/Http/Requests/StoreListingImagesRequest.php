<?php

namespace App\Domains\Listings\Http\Requests;

use App\Domains\Catalog\Support\ProductImages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Seller Center: add product images from a listing (G-W1).
 */
class StoreListingImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.ProductImages::MAX_PER_VENDOR_PRODUCT],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.ProductImages::MAX_KB],
            'alt_en' => ['nullable', 'string', 'max:255'],
            'alt_ar' => ['nullable', 'string', 'max:255'],
            'variant_only' => ['nullable', 'boolean'],
        ];
    }
}
