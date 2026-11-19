<?php

namespace App\Domains\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpFoundation\Response;

/**
 * D16: Reverb channel authorisation for token clients (the website through
 * its same-origin proxy `/api/nawy/{market}/broadcasting/auth`, which adds
 * the bearer token from the httpOnly cookie; the apps with their token).
 * The Blade panels use the session route `/broadcasting/auth` instead.
 * Answers Pusher's `{auth}` body as is (no API envelope); a channel the user
 * may not join is a 403.
 */
class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->validate([
            'socket_id' => ['required', 'string', 'max:64'],
            'channel_name' => ['required', 'string', 'max:191'],
        ]);

        $answer = Broadcast::auth($request);

        return $answer instanceof Response ? $answer : new JsonResponse($answer ?? []);
    }
}
