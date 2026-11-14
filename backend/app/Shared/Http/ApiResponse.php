<?php

namespace App\Shared\Http;

use Illuminate\Http\JsonResponse;

/**
 * The single JSON envelope for every API response:
 * success → {"data": …, "meta": {…}}; failure → {"error": {"code", "message", "fields"}}.
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => (object) $meta], $status);
    }

    /**
     * @param  array<string, array<int, string>>  $fields
     */
    public static function error(string $code, string $message, int $status, array $fields = []): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields],
        ], $status);
    }
}
