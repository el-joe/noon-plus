<?php

namespace App\Domains\Listings\Http\Requests;

use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Catalog\Support\ProductImages;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Models\Listing;
use App\Domains\Vendors\Support\VendorWorkspace;
use Brick\Money\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A seller lists an existing catalogue variant (FR-CAT-2): one listing per
 * variant and condition per store.
 */
class StoreListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(VendorWorkspace::class)->has();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $decimals = Currency::of(app(CountryContext::class)->current()->currency)->getDefaultFractionDigits();
        $vendorId = app(VendorWorkspace::class)->vendor()->id;

        return [
            'product_variant_id' => [
                'required', 'integer',
                Rule::exists(ProductVariant::class, 'id'),
                Rule::unique(Listing::class, 'product_variant_id')
                    ->where('vendor_id', $vendorId)
                    ->where('condition', (string) $this->input('condition')),
            ],
            'condition' => ['required', Rule::in(config('nawy.vendors.listing_conditions'))],
            'fulfilment' => ['required', Rule::in(config('nawy.vendors.fulfilment_models'))],
            'price' => ['required', 'decimal:0,'.$decimals, 'gt:0', 'max:9999999'],
            'sale_price' => ['nullable', 'decimal:0,'.$decimals, 'gt:0', 'lt:price'],
            'handling_days' => ['required', 'integer', 'between:0,30'],
            'stock' => ['required', 'integer', 'between:0,100000'],
            'vendor_sku' => ['nullable', 'string', 'max:64'],
            'intent' => ['required', Rule::in(['draft', 'submit'])],
            'images' => ['nullable', 'array', 'max:'.ProductImages::MAX_PER_VENDOR_PRODUCT],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.ProductImages::MAX_KB],
            'image_alt_en' => ['nullable', 'string', 'max:255'],
            'image_alt_ar' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['product_variant_id.unique' => __('You already list this product in this condition.')];
    }
}
