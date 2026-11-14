<?php

namespace App\Domains\Identity\Enums;

enum LoginOutcome: string
{
    case Succeeded = 'succeeded';
    case WrongCredentials = 'wrong_credentials';
    case NoPanelAccess = 'no_panel_access';
    case SecondStepSent = 'second_step_sent';
    case WrongCode = 'wrong_code';
    case Locked = 'locked';
}
