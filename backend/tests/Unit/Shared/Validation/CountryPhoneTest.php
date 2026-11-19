<?php

use App\Domains\Countries\Models\Country;
use App\Shared\Validation\CountryPhone;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('normalises a phone number of the country to E.164', function (string $countryCode, string $input, string $expected) {
    $country = new Country(Country::referenceAttributes($countryCode));

    expect(CountryPhone::normalize($input, $country))->toBe($expected);
})->with([
    'UAE international' => ['uae', '+971501234567', '+971501234567'],
    'UAE international with spaces' => ['uae', '+971 50 123 4567', '+971501234567'],
    'UAE with 00 prefix' => ['uae', '00971501234567', '+971501234567'],
    'UAE local with trunk zero' => ['uae', '050-123-4567', '+971501234567'],
    'UAE national without prefix' => ['uae', '501234567', '+971501234567'],
    'Oman eight digits' => ['omn', '+968 9123 4567', '+96891234567'],
    'Egypt ten digits with brackets' => ['egy', '(010) 1234 5678', '+201012345678'],
    'Kuwait local' => ['kwt', '51234567', '+96551234567'],
]);

it('rejects numbers that are not of the country', function (string $countryCode, string $input) {
    $country = new Country(Country::referenceAttributes($countryCode));

    expect(CountryPhone::normalize($input, $country))->toBeNull();
})->with([
    'too short for the UAE' => ['uae', '+97150123456'],
    'too long for the UAE' => ['uae', '+9715012345678'],
    'another country code' => ['uae', '+96891234567'],
    'letters' => ['uae', '+97150123abcd'],
    'national number starting with zero' => ['uae', '+9710501234567'],
    'Oman number too long' => ['omn', '+968912345678'],
    'empty' => ['uae', ''],
]);
