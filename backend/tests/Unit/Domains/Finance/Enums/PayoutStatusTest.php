<?php

use App\Domains\Finance\Enums\PayoutStatus as S;

it('allows exactly the payout moves of the lifecycle', function (S $from, array $next) {
    expect($from->allowedNext())->toEqualCanonicalizing($next);
})->with([
    'pending (recalculate keeps it pending)' => [S::Pending, [S::Approved, S::OnHold, S::Pending]],
    'on hold' => [S::OnHold, [S::Pending]],
    'approved' => [S::Approved, [S::Processing]],
    'processing' => [S::Processing, [S::Completed, S::Failed]],
    'failed is retried' => [S::Failed, [S::Processing]],
    'completed is final' => [S::Completed, []],
]);
