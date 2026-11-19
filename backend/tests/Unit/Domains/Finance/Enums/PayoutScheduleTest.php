<?php

use App\Domains\Finance\Enums\PayoutSchedule;
use Carbon\CarbonImmutable;

it('is due on the schedule day in the local calendar', function (PayoutSchedule $schedule, string $date, bool $expected) {
    expect($schedule->isDue(CarbonImmutable::parse($date)))->toBe($expected);
})->with([
    'weekly on a Monday' => [PayoutSchedule::Weekly, '2026-11-16', true],
    'weekly on a Sunday' => [PayoutSchedule::Weekly, '2026-11-15', false],
    'biweekly on the 1st' => [PayoutSchedule::Biweekly, '2026-11-01', true],
    'biweekly on the 15th' => [PayoutSchedule::Biweekly, '2026-11-15', true],
    'biweekly on the 16th' => [PayoutSchedule::Biweekly, '2026-11-16', false],
    'monthly on the 1st' => [PayoutSchedule::Monthly, '2026-12-01', true],
    'monthly on the 15th' => [PayoutSchedule::Monthly, '2026-11-15', false],
]);
