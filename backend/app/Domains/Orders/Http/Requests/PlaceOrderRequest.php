<?php

namespace App\Domains\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
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
            'payment_method' => ['required', 'in:cod,card'],
            'address' => ['required', 'array'],
            'address.name' => ['required', 'string', 'max:120'],
            'address.phone' => ['nullable', 'string', 'max:32'],
            'address.city' => ['required', 'string', 'max:120'],
            'address.area' => ['required', 'string', 'max:120'],
            'address.street' => ['required', 'string', 'max:255'],
            'address.building' => ['nullable', 'string', 'max:120'],
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
