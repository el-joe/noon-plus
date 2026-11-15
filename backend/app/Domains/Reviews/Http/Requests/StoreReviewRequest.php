<?php

namespace App\Domains\Reviews\Http\Requests;

use App\Domains\Reviews\Support\ProductReviews;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A verified buyer reviews one delivered order item (multipart when photos are attached).
 */
class StoreReviewRequest extends FormRequest
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
            'order_item_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.ProductReviews::MAX_PHOTOS],
            'photos.*' => ['image', 'max:5120'],
        ];
    }
}
