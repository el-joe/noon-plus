<?php

namespace App\Shared\Notifications\Email\Http;

use App\Http\Controllers\Controller;
use App\Shared\Notifications\Email\Unsubscribes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Unsubscribe endpoints. API (`/api/v1/email/unsubscribe/{token}`): GET
 * describes the token for the website landing page, POST unsubscribes
 * (also the RFC 8058 one-click target of `List-Unsubscribe-Post`),
 * DELETE re-subscribes. Web (`/email/unsubscribe/{token}`): the landing
 * page for panel users and guests.
 */
class UnsubscribeController extends Controller
{
    public function __construct(private Unsubscribes $unsubscribes) {}

    public function show(string $token): JsonResponse
    {
        $data = $this->unsubscribes->describe($token);

        abort_if($data === null, 404, __('This unsubscribe link is not valid.'));

        return response()->json(['data' => $data]);
    }

    public function unsubscribe(string $token): JsonResponse
    {
        abort_unless($this->unsubscribes->unsubscribe($token), 404, __('This unsubscribe link is not valid.'));

        return response()->json(['data' => $this->unsubscribes->describe($token)]);
    }

    public function resubscribe(string $token): JsonResponse
    {
        abort_unless($this->unsubscribes->resubscribe($token), 404, __('This unsubscribe link is not valid.'));

        return response()->json(['data' => $this->unsubscribes->describe($token)]);
    }

    public function page(string $token): View
    {
        return view('emails.unsubscribe', ['token' => $token, 'data' => $this->unsubscribes->describe($token)]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $resubscribe = $request->boolean('resubscribe');
        $done = $resubscribe ? $this->unsubscribes->resubscribe($token) : $this->unsubscribes->unsubscribe($token);

        abort_unless($done, 404, __('This unsubscribe link is not valid.'));

        return redirect()->route('email.unsubscribe.show', ['token' => $token])->with('status', $resubscribe ? __('You are subscribed again.') : __('You are unsubscribed.'));
    }
}
