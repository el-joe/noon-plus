<?php

namespace App\Domains\Customers\Support;

use App\Domains\Customers\Enums\CustomerAccountStatus;
use App\Domains\Orders\Enums\ActorType;
use App\Models\User;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * Customer account lifecycle (catalogue Appendix A; QC-016): a reason is
 * required, the move is recorded in `user_status_transitions` with the
 * admin, and suspending or banning revokes every API token so the person
 * is signed out everywhere. Sign-in refuses accounts that are not active.
 */
class CustomerAccountStateMachine
{
    public function move(User $user, CustomerAccountStatus $to, ActorType $actorType, ?int $actorUserId, string $reason): User
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new TransitionRefused(__('Give a reason.'));
        }

        return DB::transaction(function () use ($user, $to, $actorType, $actorUserId, $reason): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $from = CustomerAccountStatus::tryFrom((string) $locked->status) ?? CustomerAccountStatus::Active;

            if (! in_array($to, $from->allowedNext(), true)) {
                throw new TransitionRefused(__('This cannot move from :from to :to.', ['from' => $from->value, 'to' => $to->value]));
            }

            $locked->forceFill(['status' => $to->value])->save();
            DB::table('user_status_transitions')->insert([
                'user_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actorType->value,
                'actor_user_id' => $actorUserId,
                'reason' => mb_substr(trim($reason), 0, 255),
                'created_at' => now(),
            ]);

            if ($to !== CustomerAccountStatus::Active) {
                $locked->tokens()->delete();
            }

            $user->setRawAttributes($locked->getAttributes(), true);

            return $user;
        });
    }
}
