<?php

namespace App\Domains\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewIndexRequest extends FormRequest
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
            'sort' => ['nullable', 'in:recent,helpful,rating_desc,rating_asc'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'with_photos' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ];
    }
}
