<?php

namespace App\Shared\StateMachine;

use App\Domains\Orders\Enums\ActorType;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The guarded move for lifecycles whose `status` column is read as a plain
 * string across the code base (product, vendor account; QC-016): the enum
 * class decides the allowed moves and the history row goes to
 * `state_transitions` like every other machine.
 */
class StringStatusMachine
{
    /**
     * @param  class-string<BackedEnum>  $enum  an enum with allowedNext()
     * @param  array<string, mixed>  $attributes
     */
    public function move(Model $subject, string $enum, BackedEnum $to, ActorType $actorType, ?int $actorUserId, ?string $reason = null, array $attributes = []): Model
    {
        return DB::connection($subject->getConnectionName())->transaction(function () use ($subject, $enum, $to, $actorType, $actorUserId, $reason, $attributes): Model {
            $locked = $subject->newQuery()->lockForUpdate()->findOrFail($subject->getKey());
            $from = $enum::tryFrom((string) $locked->status);

            if ($from === null || ! in_array($to, $from->allowedNext(), true)) {
                throw new TransitionRefused(__('This cannot move from :from to :to.', [
                    'from' => str_replace('_', ' ', (string) $locked->status),
                    'to' => str_replace('_', ' ', $to->value),
                ]));
            }

            $locked->forceFill(['status' => $to->value] + $attributes)->save();
            StateTransition::query()->create([
                'subject_type' => method_exists($locked, 'transitionSubject') ? $locked->transitionSubject() : strtolower(class_basename($locked)),
                'subject_id' => $locked->getKey(),
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            ]);
            $subject->setRawAttributes($locked->getAttributes(), true);

            return $subject;
        });
    }
}
