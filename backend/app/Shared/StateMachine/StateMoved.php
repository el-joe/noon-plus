<?php

namespace App\Shared\StateMachine;

use App\Domains\Orders\Enums\ActorType;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * A lifecycle moved (dispatched by {@see StateMachine::move()} and the
 * sub-order machine) — listeners add side effects that every lifecycle
 * shares, e.g. the customer's in-app notifications (G-W29).
 */
class StateMoved
{
    public function __construct(
        public Model $subject,
        public ?BackedEnum $from,
        public BackedEnum $to,
        public ActorType $actorType,
        public ?int $actorUserId = null,
        public ?string $reason = null,
    ) {}
}
