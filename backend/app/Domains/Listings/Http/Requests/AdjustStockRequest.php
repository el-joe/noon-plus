<?php

namespace App\Domains\Listings\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A stock movement with a reason (SRS §5.2 FR-LST-3): positive adds units,
 * negative removes them.
 */
class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'reason' => ['required', Rule::in(config('nawy.vendors.stock_reasons'))],
        ];
    }
}
