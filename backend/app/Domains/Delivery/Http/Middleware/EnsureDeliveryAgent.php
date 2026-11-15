<?php

namespace App\Domains\Delivery\Http\Middleware;

use App\Domains\Delivery\Support\AgentWorkspace;
use App\Domains\Identity\Support\TokenScopes;
use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delivery app / delivery panel: only an active agent of this country gets
 * through (on the API only with a `delivery` token, QA-001); the profile is put on the request as `delivery_agent`.
 */
class EnsureDeliveryAgent
{
    public function __construct(private AgentWorkspace $workspace) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($request->is('api/*') && ! ($token instanceof PersonalAccessToken && in_array(TokenScopes::DELIVERY, (array) $token->abilities, true))) {
            return ApiResponse::error('wrong_token', __('Sign in with the delivery app to continue.'), 403);
        }

        $agent = $request->user() === null ? null : $this->workspace->agentFor($request->user());

        if ($agent === null) {
            if ($request->is('api/*')) {
                return ApiResponse::error('not_a_delivery_agent', __('This account is not an active delivery agent in this country.'), 403);
            }

            abort(403, __('This account is not an active delivery agent in this country.'));
        }

        $request->attributes->set('delivery_agent', $agent);

        return $next($request);
    }
}
