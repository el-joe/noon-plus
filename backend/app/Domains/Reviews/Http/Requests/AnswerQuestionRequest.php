<?php

namespace App\Domains\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A seller (Seller Center) or Nawy (admin) answers a product question.
 */
class AnswerQuestionRequest extends FormRequest
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
        return ['body' => ['required', 'string', 'min:2', 'max:2000']];
    }
}
