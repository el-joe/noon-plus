<?php

use App\Domains\Returns\Enums\ReturnStatus as S;

it('allows exactly the return moves of the lifecycle', function (S $from, array $next) {
    expect($from->allowedNext())->toEqualCanonicalizing($next);
})->with([
    'requested' => [S::Requested, [S::Approved, S::Rejected, S::Cancelled]],
    'approved' => [S::Approved, [S::AwaitingPickup, S::InTransit, S::Received, S::Cancelled]],
    'awaiting pickup' => [S::AwaitingPickup, [S::InTransit]],
    'in transit' => [S::InTransit, [S::Received]],
    'received' => [S::Received, [S::Inspecting]],
    'inspecting' => [S::Inspecting, [S::Completed]],
    'rejected is final' => [S::Rejected, []],
    'completed is final' => [S::Completed, []],
    'cancelled is final' => [S::Cancelled, []],
]);

it('lists the open statuses as stored values', function () {
    expect(array_values(S::openValues()))->toEqualCanonicalizing(['requested', 'approved', 'awaiting_pickup', 'in_transit', 'received', 'inspecting']);
});

it('is open until the return is rejected, completed or cancelled', function (S $status) {
    expect($status->isOpen())->toBe(! in_array($status, [S::Rejected, S::Completed, S::Cancelled], true));
})->with(S::cases());
