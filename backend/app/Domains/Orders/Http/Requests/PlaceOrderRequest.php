<?php

namespace App\Domains\Orders\Http\Requests;

use App\Domains\Customers\Models\CustomerAddress;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Shared\Validation\CountryPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The delivery address is either a saved one (`address_id`, from the
     * customer's address book in this country) or inline (`address`).
     * `payment_method` is a code from GET checkout/payment-methods, or "card"
     * for the country's recommended card gateway.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'string', Rule::in([...array_column(PaymentMethod::cases(), 'value'), 'card'])],
            'address_id' => ['nullable', 'integer', Rule::exists(CustomerAddress::class, 'id')->where('customer_user_id', $this->user()?->id)],
            'address' => ['required_without:address_id', 'prohibits:address_id', 'array'],
            'address.name' => ['required_with:address', 'string', 'max:120'],
            'address.phone' => ['nullable', 'string', 'max:32', new CountryPhone],
            'address.city' => ['required_with:address', 'string', 'max:120'],
            'address.area' => ['required_with:address', 'string', 'max:120'],
            'address.street' => ['required_with:address', 'string', 'max:255'],
            'address.building' => ['nullable', 'string', 'max:120'],
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'referral_code' => ['nullable', 'string', 'max:16'],
            'visitor_token' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * The address snapshot for the order.
     *
     * @return array<string, mixed>
     */
    public function shippingAddress(): array
    {
        if ($this->validated('address_id') !== null) {
            return CustomerAddress::query()->where('customer_user_id', $this->user()->id)->findOrFail((int) $this->validated('address_id'))->toOrderAddress();
        }

        $address = $this->validated('address');

        if (filled($address['phone'] ?? null)) {
            $address['phone'] = CountryPhone::normalize($address['phone']);
        }

        return $address;
    }
}
