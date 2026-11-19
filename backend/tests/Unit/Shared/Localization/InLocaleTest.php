<?php

use App\Shared\Localization\InLocale;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

beforeEach(fn () => app()->setLocale('en'));

it('runs the callback in the given locale and restores the previous one', function () {
    $seen = InLocale::run('ar', fn (): string => app()->getLocale());

    expect($seen)->toBe('ar')
        ->and(app()->getLocale())->toBe('en');
});

it('keeps the current locale when none is given', function (?string $locale) {
    $seen = InLocale::run($locale, fn (): string => app()->getLocale());

    expect($seen)->toBe('en')
        ->and(app()->getLocale())->toBe('en');
})->with([
    'null' => [null],
    'empty' => [''],
    'the same locale' => ['en'],
]);

it('restores the previous locale when the callback throws', function () {
    try {
        InLocale::run('ar', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect(app()->getLocale())->toBe('en');
});

it('returns what the callback returns', function () {
    expect(InLocale::run('ar', fn (): array => ['ok' => true]))->toBe(['ok' => true]);
});
