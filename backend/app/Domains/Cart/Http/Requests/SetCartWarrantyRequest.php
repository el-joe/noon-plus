<?php

namespace App\Domains\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetCartWarrantyRequest extends FormRequest
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
            'warranty_plan_id' => ['present', 'nullable', 'integer'],
        ];
    }
}
