<?php

namespace App\Domains\Countries\Http\Resources;

use App\Domains\Countries\Models\Country;
use Brick\Money\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Country
 */
class CountryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'iso2' => $this->iso2,
            'name' => app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en,
            // G-A8: both names, so a picker can show Arabic whatever the request language.
            'names' => ['en' => $this->name_en, 'ar' => $this->name_ar],
            'currency' => [
                'code' => $this->currency,
                'decimals' => Currency::of($this->currency)->getDefaultFractionDigits(),
            ],
            'timezone' => $this->timezone,
            'weekend' => $this->weekend,
            'tax' => ['name' => $this->tax_name, 'rate' => $this->tax_rate],
            'phone_code' => $this->phone_code,
            'markets' => collect($this->locales)->map(fn (string $locale): string => $this->marketSlug($locale))->values(),
            'default_locale' => $this->default_locale,
        ];
    }
}
