<?php

use App\Domains\Orders\Enums\SubOrderStatus;
use App\Shared\Localization\InLocale;
use App\Shared\Localization\Label;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('humanises machine values into translation keys', function (BackedEnum|string|null $value, string $expected) {
    expect(Label::key($value))->toBe($expected);
})->with([
    'snake case string' => ['pending_review', 'Pending review'],
    'backed enum value' => [SubOrderStatus::OutForDelivery, 'Out for delivery'],
    'colon separated permission' => ['vendor:product', 'Vendor product'],
    'single word' => ['active', 'Active'],
    'override for a value that does not read well' => ['returns_refunds', 'Returns & refunds'],
    'null' => [null, ''],
    'empty string' => ['', ''],
]);

it('translates the label in the current locale', function () {
    $label = InLocale::run('ar', fn (): string => Label::of('pending_review'));

    expect($label)->toBe('قيد المراجعة')
        ->and(Label::of('pending_review'))->toBe('Pending review');
});

it('returns an empty label without translating for an empty value', function () {
    expect(Label::of(null))->toBe('');
});
