<?php

namespace App\Domains\Listings\Http\Requests;

use App\Domains\Countries\Support\CountryContext;
use Brick\Money\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Price and handling changes on a seller's own listing (ownership is checked
 * by the controller through the store-scoped lookup and ListingPolicy).
 */
class UpdateListingRequest extends FormRequest
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
        $decimals = Currency::of(app(CountryContext::class)->current()->currency)->getDefaultFractionDigits();

        return [
            'price' => ['required', 'decimal:0,'.$decimals, 'gt:0', 'max:9999999'],
            'sale_price' => ['nullable', 'decimal:0,'.$decimals, 'gt:0', 'lt:price'],
            'handling_days' => ['required', 'integer', 'between:0,30'],
            'vendor_sku' => ['nullable', 'string', 'max:64'],
        ];
    }
}
