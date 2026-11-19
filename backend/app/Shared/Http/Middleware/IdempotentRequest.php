<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client `Idempotency-Key` (QC-044, MASTER_PLAN ADR 13) for requests that
 * must not run twice, such as placing an order. A retry with the same key
 * (same caller, same endpoint) gets the first answer again, marked
 * `Idempotent-Replayed: true`; the same key with a different body answers
 * 422 `idempotency_key_reused`; a retry while the first request is still
 * running answers 409 `request_in_progress`. Answers are kept 24 hours;
 * server errors (5xx) are not kept, so they can be retried. Without the
 * header the request runs as usual.
 */
class IdempotentRequest
{
    public const HEADER = 'Idempotency-Key';

    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || $key === '') {
            return $next($request);
        }

        if (preg_match('/^[A-Za-z0-9_:.\-]{8,100}$/', $key) !== 1) {
            return ApiResponse::error('validation_failed', __('The Idempotency-Key header must be 8–100 letters, digits or - _ : .'), 422, [self::HEADER => [__('Use 8–100 letters, digits or - _ : .')]]);
        }

        $caller = $request->user('sanctum')?->getAuthIdentifier() ?? 'ip:'.$request->ip();
        $scope = 'idempotency:'.sha1($caller.'|'.$request->method().'|'.($request->route()?->getName() ?? $request->path()).'|'.$key);
        $fingerprint = sha1((string) $request->getContent());
        $stored = Cache::get($scope);

        if (is_array($stored)) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = Cache::lock($scope.':lock', 60);

        if (! $lock->get()) {
            return ApiResponse::error('request_in_progress', __('This request is already being processed.'), 409);
        }

        try {
            $stored = Cache::get($scope);

            if (is_array($stored)) {
                return $this->replay($stored, $fingerprint);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                Cache::put($scope, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'content' => (string) $response->getContent(),
                    'content_type' => (string) $response->headers->get('Content-Type', 'application/json'),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{fingerprint: string, status: int, content: string, content_type: string}  $stored
     */
    private function replay(array $stored, string $fingerprint): Response
    {
        if ($stored['fingerprint'] !== $fingerprint) {
            return ApiResponse::error('idempotency_key_reused', __('This Idempotency-Key was already used for a different request.'), 422);
        }

        return response($stored['content'], $stored['status'], ['Content-Type' => $stored['content_type'], 'Idempotent-Replayed' => 'true']);
    }
}
