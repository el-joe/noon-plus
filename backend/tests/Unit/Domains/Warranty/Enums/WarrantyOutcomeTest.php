<?php

use App\Domains\Warranty\Enums\WarrantyClaimStatus;
use App\Domains\Warranty\Enums\WarrantyOutcome;

it('moves an approved claim to the status of its outcome', function (WarrantyOutcome $outcome, WarrantyClaimStatus $status) {
    expect($outcome->status())->toBe($status)
        ->and(WarrantyClaimStatus::Approved->allowedNext())->toContain($status);
})->with([
    [WarrantyOutcome::Repair, WarrantyClaimStatus::InRepair],
    [WarrantyOutcome::Replace, WarrantyClaimStatus::ReplacementSent],
    [WarrantyOutcome::Refund, WarrantyClaimStatus::RefundIssued],
]);
