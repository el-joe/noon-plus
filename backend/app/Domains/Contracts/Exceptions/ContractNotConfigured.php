<?php

namespace App\Domains\Contracts\Exceptions;

use RuntimeException;

/**
 * A category or product requires an individual contract but the country has
 * none configured (SRS §13.5 step 4). Never replaced by the global agreement
 * or another country's template.
 */
class ContractNotConfigured extends RuntimeException {}
