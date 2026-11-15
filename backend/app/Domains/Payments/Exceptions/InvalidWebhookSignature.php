<?php

namespace App\Domains\Payments\Exceptions;

use RuntimeException;

class InvalidWebhookSignature extends RuntimeException {}
