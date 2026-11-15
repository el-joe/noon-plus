<?php

namespace App\Shared\Validation;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A mobile / landline number of the current country (owner decision WP6:
 * guests leave a phone validated per country). Accepted as international
 * (+971 50 123 4567, 00971…) or local with the trunk 0 (050 123 4567);
 * {@see self::normalize()} gives the E.164 form that is stored. National
 * number lengths: `config('nawy.phone_national_digits')`.
 */
class CountryPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::normalize($value) === null) {
            $fail(__('Enter a valid :country phone number.', ['country' => app(CountryContext::class)->current()->name_en]));
        }
    }

    /**
     * E.164 number of the current country, or null when it is not one.
     */
    public static function normalize(string $value, ?Country $country = null): ?string
    {
        $country ??= app(CountryContext::class)->current();
        $code = ltrim((string) $country->phone_code, '+');
        $digits = (array) config('nawy.phone_national_digits.'.$country->code, [7, 8, 9, 10]);
        $number = preg_replace('/[\s\-().]/', '', $value) ?? '';

        $national = match (true) {
            str_starts_with($number, '+'.$code) => substr($number, strlen($code) + 1),
            str_starts_with($number, '00'.$code) => substr($number, strlen($code) + 2),
            str_starts_with($number, '0') => substr($number, 1),
            default => $number,
        };

        if (! ctype_digit($national) || ! in_array(strlen($national), $digits, true) || str_starts_with($national, '0')) {
            return null;
        }

        return '+'.$code.$national;
    }
}
