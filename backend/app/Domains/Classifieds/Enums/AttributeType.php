<?php

namespace App\Domains\Classifieds\Enums;

/**
 * Dynamic attribute types of a classified category (OpenSooq audit:
 * select / multi-select / number / year / text / boolean).
 */
enum AttributeType: string
{
    case Text = 'text';
    case Number = 'number';
    case Year = 'year';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Boolean = 'boolean';

    public function isNumeric(): bool
    {
        return in_array($this, [self::Number, self::Year], true);
    }
}
