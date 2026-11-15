<?php

namespace App\Domains\Panels\Http\Controllers\Api\Concerns;

use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Shared\Http\ApiResponse;
use App\Shared\StateMachine\TransitionRefused;
use Closure;
use Illuminate\Http\JsonResponse;

/**
 * Ops app actions: a step the state machine or a guard refuses answers
 * 422 `transition_refused` with the refusal message.
 */
trait RefusesTransitions
{
    /**
     * @param  Closure(): JsonResponse  $action
     */
    protected function refusable(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (TransitionRefused|SubOrderTransitionRefused $refused) {
            return ApiResponse::error('transition_refused', $refused->getMessage(), 422);
        }
    }
}
