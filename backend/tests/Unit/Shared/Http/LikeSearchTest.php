<?php

use App\Shared\Http\LikeSearch;

it('wraps search text in wildcards and escapes LIKE metacharacters', function (string $input, string $expected) {
    expect(LikeSearch::contains($input))->toBe($expected);
})->with([
    'plain text' => ['shoes', '%shoes%'],
    'percent matches literally' => ['50%', '%50\\%%'],
    'underscore matches literally' => ['a_b', '%a\\_b%'],
    'backslash is escaped first' => ['a\\b', '%a\\\\b%'],
    'backslash before percent is not double-escaped' => ['\\%', '%\\\\\\%%'],
    'empty text matches everything' => ['', '%%'],
    'arabic text is kept' => ['هاتف', '%هاتف%'],
]);
