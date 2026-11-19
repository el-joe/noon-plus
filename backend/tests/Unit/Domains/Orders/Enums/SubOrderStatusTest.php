<?php

use App\Domains\Orders\Enums\SubOrderStatus as S;

it('allows exactly the sub-order moves of the lifecycle', function (S $from, array $next) {
    expect($from->allowedNext())->toEqualCanonicalizing($next);
})->with([
    'placed' => [S::Placed, [S::Confirmed, S::Cancelled]],
    'confirmed' => [S::Confirmed, [S::Processing, S::Cancelled]],
    'processing' => [S::Processing, [S::Packed, S::Cancelled]],
    'packed' => [S::Packed, [S::Shipped, S::Cancelled]],
    'shipped (direct delivery skips out for delivery)' => [S::Shipped, [S::OutForDelivery, S::Delivered, S::Cancelled]],
    'out for delivery' => [S::OutForDelivery, [S::Delivered, S::Cancelled]],
    'delivered (admin force-cancel included)' => [S::Delivered, [S::Completed, S::Returned, S::Cancelled]],
    'returned' => [S::Returned, [S::Refunded]],
    'completed is final' => [S::Completed, []],
    'cancelled is final' => [S::Cancelled, []],
    'refunded is final' => [S::Refunded, []],
]);

it('treats only the statuses before the parcel ships as before shipping', function (S $status, bool $expected) {
    expect($status->isBeforeShipping())->toBe($expected);
})->with([
    [S::Placed, true],
    [S::Confirmed, true],
    [S::Processing, true],
    [S::Packed, true],
    [S::Shipped, false],
    [S::OutForDelivery, false],
    [S::Delivered, false],
    [S::Cancelled, false],
]);
