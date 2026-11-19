<?php

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\LiveMedia\Support\WebRtcSignalling;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpFoundation\Response;

/**
 * D16: Reverb channel authorisation for token clients (the website through
 * its same-origin proxy `/api/nawy/{market}/broadcasting/auth`, which adds
 * the bearer token from the httpOnly cookie; the apps with their token).
 * The Blade panels use the session route `/broadcasting/auth` instead.
 * Answers Pusher's `{auth}` body as is (no API envelope); a channel the user
 * may not join is a 403.
 *
 * D18: a WebRTC viewer's own channel `private-live.{slug}.peer.{peer}` is
 * authorised by its signed `peer_token` (guests included, no sign-in);
 * every other channel still needs a signed-in user (401 otherwise).
 */
class BroadcastAuthController extends Controller
{
    private const PEER_CHANNEL = '/^private-live\.([a-z0-9-]{1,80})\.peer\.([a-z0-9]{8,40})$/';

    public function __invoke(Request $request, WebRtcSignalling $webrtc): Response
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:191'],
            'peer_token' => ['nullable', 'string', 'max:512'],
        ]);

        if (preg_match(self::PEER_CHANNEL, $data['channel_name'], $match) === 1) {
            if (! $webrtc->mayJoinPeerChannel($match[1], $match[2], $data['peer_token'] ?? null)) {
                return ApiResponse::error('forbidden', __('This viewer session expired. Join again.'), 403);
            }

            return new JsonResponse(['auth' => $this->signature($data['socket_id'], $data['channel_name'])]);
        }

        $user = $request->user('sanctum');

        if ($user === null) {
            return ApiResponse::error('unauthenticated', __('Sign in to continue.'), 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $answer = Broadcast::auth($request);

        return $answer instanceof Response ? $answer : new JsonResponse($answer ?? []);
    }

    /**
     * Pusher's private-channel signature `{key}:{HMAC-SHA256(socket_id:channel)}`
     * with the app secret of the default broadcaster (Reverb).
     */
    private function signature(string $socketId, string $channel): string
    {
        $connection = (array) config('broadcasting.connections.'.config('broadcasting.default'));

        if (($connection['key'] ?? '') === '') {
            $connection = (array) config('broadcasting.connections.reverb');
        }

        return ($connection['key'] ?? '').':'.hash_hmac('sha256', $socketId.':'.$channel, (string) $connection['secret']);
    }
}
