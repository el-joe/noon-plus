<?php

namespace App\Domains\Identity\Support\Jwt;

use RuntimeException;

/**
 * An identity token failed a signature or claim check.
 */
class InvalidJwt extends RuntimeException {}
