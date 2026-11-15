<?php

namespace App\Domains\Newsletter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NewsletterTokenRequest extends FormRequest
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
        return ['token' => ['required', 'string', 'size:48']];
    }
}
