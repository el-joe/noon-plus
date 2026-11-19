<?php

use App\Domains\Listings\Enums\ListingStatus as S;

it('allows exactly the listing moves of the lifecycle', function (S $from, array $next) {
    expect($from->allowedNext())->toEqualCanonicalizing($next);
})->with([
    'draft' => [S::Draft, [S::PendingReview, S::Archived]],
    'pending review' => [S::PendingReview, [S::Active, S::Rejected, S::Archived]],
    'rejected is resubmitted' => [S::Rejected, [S::PendingReview, S::Archived]],
    'active' => [S::Active, [S::Paused, S::OutOfStock, S::Archived]],
    'paused' => [S::Paused, [S::Active, S::Archived]],
    'out of stock' => [S::OutOfStock, [S::Active, S::Archived]],
    'archived is final' => [S::Archived, []],
]);

it('answers whether a move is allowed', function (S $from, S $to, bool $expected) {
    expect($from->canMoveTo($to))->toBe($expected);
})->with([
    'draft to review' => [S::Draft, S::PendingReview, true],
    'draft cannot skip review' => [S::Draft, S::Active, false],
    'rejected cannot go live without review' => [S::Rejected, S::Active, false],
    'archived cannot come back' => [S::Archived, S::Active, false],
    'paused cannot run out of stock' => [S::Paused, S::OutOfStock, false],
]);

it('is sellable only while active', function (S $status) {
    expect($status->isSellable())->toBe($status === S::Active);
})->with(S::cases());
