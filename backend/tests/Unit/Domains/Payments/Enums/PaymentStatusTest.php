<?php

use App\Domains\Payments\Enums\PaymentStatus as S;

it('allows exactly the payment moves of the lifecycle', function (S $from, array $next) {
    expect($from->allowedNext())->toEqualCanonicalizing($next);
})->with([
    'pending' => [S::Pending, [S::Authorized, S::Captured, S::Failed, S::Expired, S::UnderReview, S::Voided]],
    'authorized' => [S::Authorized, [S::Captured, S::Failed, S::Refunded, S::UnderReview]],
    'under review' => [S::UnderReview, [S::Captured, S::Failed, S::Refunded]],
    'captured' => [S::Captured, [S::PartiallyRefunded, S::Refunded]],
    'partially refunded takes further partial refunds' => [S::PartiallyRefunded, [S::PartiallyRefunded, S::Refunded]],
    'failed is final' => [S::Failed, []],
    'expired is final' => [S::Expired, []],
    'refunded is final' => [S::Refunded, []],
    'voided is final' => [S::Voided, []],
]);

it('never moves a captured payment backwards', function (S $backwards) {
    expect(S::Captured->allowedNext())->not->toContain($backwards);
})->with([S::Pending, S::Authorized, S::Failed, S::UnderReview]);

it('counts a payment as successful once the money reached Nawy', function (S $status, bool $expected) {
    expect($status->isSuccessful())->toBe($expected);
})->with([
    [S::Pending, false],
    [S::Authorized, true],
    [S::Captured, true],
    [S::PartiallyRefunded, true],
    [S::Refunded, true],
    [S::UnderReview, false],
    [S::Failed, false],
    [S::Expired, false],
    [S::Voided, false],
]);

it('is final exactly when nothing can follow', function (S $status, bool $expected) {
    expect($status->isFinal())->toBe($expected);
})->with([
    [S::Failed, true],
    [S::Expired, true],
    [S::Refunded, true],
    [S::Voided, true],
    [S::Pending, false],
    [S::Captured, false],
    [S::PartiallyRefunded, false],
]);
