<?php

use App\Domains\Membership\Enums\MembershipInterval;
use Carbon\CarbonImmutable;

it('ends a period without overflowing into the next month', function (MembershipInterval $interval, string $start, string $end) {
    expect($interval->after(CarbonImmutable::parse($start))->toDateString())->toBe($end);
})->with([
    'monthly' => [MembershipInterval::Monthly, '2026-03-10', '2026-04-10'],
    'monthly from the 31st of January' => [MembershipInterval::Monthly, '2026-01-31', '2026-02-28'],
    'annual' => [MembershipInterval::Annual, '2026-05-01', '2027-05-01'],
    'annual from a leap day' => [MembershipInterval::Annual, '2028-02-29', '2029-02-28'],
]);
