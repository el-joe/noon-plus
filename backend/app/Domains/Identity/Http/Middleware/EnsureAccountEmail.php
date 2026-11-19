<?php

namespace App\Domains\Identity\Http\Middleware;

use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A legacy phone-only customer signed in with a phone code has a
 * placeholder email (`requires_email`). Until they add and verify a real
 * one, every signed-in API route except reading the account, the email
 * change and sign-out answers 403 `email_required` (public routes stay
 * open). The clients show the "add your email" step on that answer.
 */
class EnsureAccountEmail
{
    /**
     * Route names an account without an email may still use.
     *
     * @var array<int, string>
     */
    public const ALLOWED = ['api.v1.me.show', 'api.v1.me.email.request', 'api.v1.me.email.verify', 'api.v1.auth.logout', 'api.v1.me.notifications.index', 'api.v1.me.notifications.unread', 'api.v1.me.push-tokens.store', 'api.v1.me.push-tokens.destroy'];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null || $request->bearerToken() === null || in_array($route->getName(), self::ALLOWED, true)
            || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            return $next($request);
        }

        $user = $request->user('sanctum');

        if ($user !== null && (bool) ($user->requires_email ?? false)) {
            return ApiResponse::error('email_required', __('Add and confirm your email to continue.'), 403);
        }

        return $next($request);
    }
}
