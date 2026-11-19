<?php

namespace App\Domains\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Satisfaction survey on a resolved ticket: 1–5 and an optional comment.
 */
class RateTicketRequest extends FormRequest
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
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
