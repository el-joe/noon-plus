<?php

namespace App\Domains\Documents\Enums;

enum DocumentRequirement: string
{
    case Mandatory = 'mandatory';
    case Optional = 'optional';
    case NotApplicable = 'not_applicable';
}
