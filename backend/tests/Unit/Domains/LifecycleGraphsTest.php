<?php

/*
| Every lifecycle status enum (an enum with `allowedNext()`, used by the
| state machines) is checked as a graph, so a new lifecycle or a new
| status is covered without adding a test here.
*/

/**
 * @return array<string, array{0: class-string<BackedEnum>}>
 */
function lifecycleEnums(): array
{
    $enums = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Domains/*/Enums/*.php') as $file) {
        $class = 'App\\Domains\\'.str_replace('/', '\\', substr($file, strpos($file, '/app/Domains/') + 13, -4));

        if (enum_exists($class) && method_exists($class, 'allowedNext')) {
            $enums[class_basename($class)] = [$class];
        }
    }

    ksort($enums);

    return $enums;
}

/**
 * Statuses a record may be created in besides the first declared case.
 */
const LIFECYCLE_EXTRA_ENTRY_STATUSES = [
    'CampaignStatus' => ['marketer_requested', 'completed'],
    'ReviewStatus' => ['auto_flagged'],
];

/**
 * Deliberate "move to the same status" (recalculate, renew, another partial refund).
 */
const LIFECYCLE_SELF_MOVES = [
    'PayoutStatus' => ['pending'],
    'MembershipStatus' => ['incomplete', 'active'],
    'VendorSubscriptionStatus' => ['active'],
    'PaymentStatus' => ['partially_refunded'],
];

/**
 * Lifecycles that never end (a record can always be moved again).
 */
const LIFECYCLES_WITHOUT_END = [
    'AgentStatus', 'DocumentStatus', 'MembershipStatus', 'ModerationStatus', 'PageStatus', 'ReplyStatus', 'StreamStatus',
];

dataset('lifecycles', lifecycleEnums());

it('finds the lifecycle enums', function () {
    expect(lifecycleEnums())->toHaveCount(65) // D12: ViewingStatus removed; D14: TravelPromotionStatus added; FBN: InboundStatus, TransferStatus, RemovalStatus; AcquisitionCommissionStatus, VendorSubscriptionStatus, ProductRequestStatus, ChangeRequestStatus, PackagingOrderStatus, FlashSaleInviteStatus (+1 from the delivery payslip / withdrawal work)
        ->toHaveKeys(['SubOrderStatus', 'ListingStatus', 'PaymentStatus', 'RefundStatus', 'ReturnStatus', 'PayoutStatus']);
});

it('has a well-formed transition graph', function (string $enum) {
    $name = class_basename($enum);
    $cases = $enum::cases();
    $values = fn (array $statuses): array => array_values(array_map(fn (BackedEnum $status): string => $status->value, $statuses));
    $targets = [];
    $selfMoves = [];

    foreach ($cases as $status) {
        $next = $status->allowedNext();

        expect($next)->each->toBeInstanceOf($enum);
        expect($values($next))->toEqual(array_values(array_unique($values($next))), "{$name}::{$status->value} lists a status twice");

        foreach ($next as $to) {
            if ($to === $status) {
                $selfMoves[] = $status;
            } else {
                $targets[$to->value] = true;
            }
        }
    }

    $entries = array_filter($cases, fn (BackedEnum $status): bool => ! isset($targets[$status->value]) && $status !== $cases[0]);
    expect($values($entries))->toEqualCanonicalizing(LIFECYCLE_EXTRA_ENTRY_STATUSES[$name] ?? [], "{$name}: undocumented entry status (nothing moves into it)");
    expect($values($selfMoves))->toEqualCanonicalizing(LIFECYCLE_SELF_MOVES[$name] ?? [], "{$name}: undocumented move to the same status");

    $seen = [$cases[0]->value => true] + array_fill_keys($values($entries), true);
    $queue = [$cases[0], ...$entries];

    while ($queue !== []) {
        foreach (array_shift($queue)->allowedNext() as $to) {
            if (! isset($seen[$to->value])) {
                $seen[$to->value] = true;
                $queue[] = $to;
            }
        }
    }

    expect(array_keys($seen))->toEqualCanonicalizing($values($cases), "{$name}: a status cannot be reached");

    $final = array_filter($cases, fn (BackedEnum $status): bool => $status->allowedNext() === []);
    expect($final !== [])->toBe(! in_array($name, LIFECYCLES_WITHOUT_END, true), "{$name}: final statuses do not match the open-ended list");
})->with('lifecycles');
