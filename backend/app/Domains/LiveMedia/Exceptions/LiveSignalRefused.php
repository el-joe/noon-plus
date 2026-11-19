<?php

namespace App\Domains\LiveMedia\Exceptions;

use RuntimeException;

/**
 * D18: a WebRTC join / signal the API refuses, answered as
 * `{error: {code, message}}` with this status (`stream_full`,
 * `stream_not_live`, `peer_token_invalid`, `peer_gone`).
 */
class LiveSignalRefused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(public readonly string $reason, string $message, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
