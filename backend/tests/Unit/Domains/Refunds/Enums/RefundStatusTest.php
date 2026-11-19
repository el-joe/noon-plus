<?php

use App\Domains\Refunds\Enums\RefundStatus as S;

it('allows exactly the refund moves of the lifecycle', function (S $from, array $next) {
    expect($from->allowedNext())->toEqualCanonicalizing($next);
})->with([
    'pending' => [S::Pending, [S::Approved, S::Rejected]],
    'approved' => [S::Approved, [S::Processing]],
    'processing' => [S::Processing, [S::Completed, S::Failed]],
    'failed is retried' => [S::Failed, [S::Processing]],
    'completed is final' => [S::Completed, []],
    'rejected is final' => [S::Rejected, []],
]);
