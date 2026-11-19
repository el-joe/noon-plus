<?php

namespace App\Domains\Identity\Enums;

/**
 * How a sign-in was made (D9 login history).
 */
enum LoginMethod: string
{
    case EmailCode = 'email_code';
    case Password = 'password';
    case Google = 'google';
    case Apple = 'apple';
    // Legacy phone-only customers' first sign-in (data-migration-plan §5.2).
    case PhoneCode = 'phone_code';
}
