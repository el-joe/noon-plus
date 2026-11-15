<?php

namespace App\Domains\Listings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Seller Center: alt text (EN/AR) and position of one product image.
 */
class UpdateListingImageRequest extends FormRequest
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
            'alt_en' => ['nullable', 'string', 'max:255'],
            'alt_ar' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'between:0,9999'],
        ];
    }
}
