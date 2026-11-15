<?php

namespace App\Shared\StateMachine;

use RuntimeException;

/**
 * A state machine refused a move (not allowed from the current status, or a
 * guard failed). The message is safe to show to the user.
 */
class TransitionRefused extends RuntimeException {}
