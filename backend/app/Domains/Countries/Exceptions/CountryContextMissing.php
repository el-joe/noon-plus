<?php

namespace App\Domains\Countries\Exceptions;

use RuntimeException;

/**
 * Thrown when country-scoped code runs without a country being selected.
 */
class CountryContextMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No country is selected for this request, job or command.');
    }
}
