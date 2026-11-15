<?php

namespace App\Domains\Panels\Http\Middleware;

use App\Domains\Panels\Support\OpsWorkspaces;
use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ops apps (`ops/{role}/…`, after `auth:sanctum`): the token must carry the
 * role's own ability `ops:{role}` (a token of another app, or a wildcard
 * customer token, is refused) and the user must still have that role's
 * workspace in this country. The tenant is put on the request as
 * `ops_workspace`; otherwise 403 `no_workspace`.
 */
class EnsureOpsWorkspace
{
    public function __construct(private OpsWorkspaces $workspaces) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $workspace = $token instanceof PersonalAccessToken && in_array("ops:{$role}", (array) $token->abilities, true)
            ? $this->workspaces->resolve($user, $role)
            : null;

        if ($workspace === null) {
            return ApiResponse::error('no_workspace', __('This account has no :role workspace in this country.', ['role' => str_replace('_', ' ', $role)]), 403);
        }

        $request->attributes->set('ops_workspace', $workspace);

        return $next($request);
    }
}
