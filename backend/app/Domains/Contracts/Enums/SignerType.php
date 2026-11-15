<?php

namespace App\Domains\Contracts\Enums;

enum SignerType: string
{
    case Vendor = 'vendor';
    case Marketer = 'marketer';
    case Customer = 'customer';
    case Agency = 'agency';
    case Applicant = 'applicant';
}
