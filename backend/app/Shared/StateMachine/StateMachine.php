<?php

namespace App\Shared\StateMachine;

use App\Domains\Orders\Enums\ActorType;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The shared move for WP4 lifecycles (ADR 7: one class per machine,
 * guarded, history with actor). The row is re-read under a lock so two
 * requests cannot both move it; the status enum's `allowedNext()` decides
 * what is allowed; the machine classes add their own guards and side
 * effects around this move.
 */
class StateMachine
{
    /**
     * @template TModel of Model
     *
     * @param  TModel  $subject  a model using {@see HasStateTransitions} whose `status` casts to an enum with allowedNext()
     * @param  array<string, mixed>  $attributes  extra columns saved with the move
     * @param  array<string, mixed>|null  $meta
     * @return TModel
     */
    public function move(Model $subject, BackedEnum $to, ActorType $actorType, ?int $actorUserId = null, ?string $reason = null, array $attributes = [], ?array $meta = null): Model
    {
        return DB::connection($subject->getConnectionName())->transaction(function () use ($subject, $to, $actorType, $actorUserId, $reason, $attributes, $meta): Model {
            $locked = $subject->newQuery()->lockForUpdate()->findOrFail($subject->getKey());
            $from = $locked->status;

            if (! in_array($to, $from->allowedNext(), true)) {
                throw new TransitionRefused(__('This cannot move from :from to :to.', [
                    'from' => str_replace('_', ' ', $from->value),
                    'to' => str_replace('_', ' ', $to->value),
                ]));
            }

            $locked->forceFill(['status' => $to] + $attributes)->save();

            StateTransition::query()->create([
                'subject_type' => $locked->transitionSubject(),
                'subject_id' => $locked->getKey(),
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
                'meta' => $meta,
            ]);

            $subject->setRawAttributes($locked->getAttributes(), true);
            event(new StateMoved($subject, $from, $to, $actorType, $actorUserId, $reason));

            return $subject;
        });
    }

    /**
     * History row for a newly created subject (→ first status).
     */
    public function created(Model $subject, ActorType $actorType, ?int $actorUserId = null, ?string $reason = null): void
    {
        StateTransition::query()->create([
            'subject_type' => $subject->transitionSubject(),
            'subject_id' => $subject->getKey(),
            'to_status' => $subject->status->value,
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
        ]);
    }
}
