<?php

namespace App\Domains\Customers\Http\Requests;

use App\Shared\Validation\CountryPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new address-book entry. The phone must be a number of the market's
 * country (CountryPhone, stored E.164); the location pin is optional but
 * comes as a latitude + longitude pair.
 */
class StoreAddressRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'label' => ['nullable', 'in:home,work,other'],
            'name' => [$required, 'string', 'max:120'],
            'phone' => [$required, 'string', 'max:32', new CountryPhone],
            'city' => [$required, 'string', 'max:120'],
            'area' => [$required, 'string', 'max:120'],
            'street' => [$required, 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:120'],
            'apartment' => ['nullable', 'string', 'max:60'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
