<?php

namespace App\Domains\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reopen a resolved ticket, optionally saying why.
 */
class ReopenTicketRequest extends FormRequest
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
        return ['message' => ['nullable', 'string', 'max:5000']];
    }
}
