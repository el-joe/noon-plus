<?php

namespace App\Domains\Finance\Exceptions;

use RuntimeException;

/**
 * A posting would take a wallet (or gift-card liability) below zero.
 */
class InsufficientFunds extends RuntimeException {}
