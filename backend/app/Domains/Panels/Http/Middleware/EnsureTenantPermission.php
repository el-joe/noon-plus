<?php

namespace App\Domains\Panels\Http\Middleware;

use App\Domains\Panels\Support\TenantPermissions;
use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `tenant.can:{panel},{group}` (QC-020): the member's staff role must grant
 * the permission group — view for GET/HEAD, full for any change. Owners
 * pass. Refused with 403 (`forbidden` on the API).
 */
class EnsureTenantPermission
{
    public function __construct(private TenantPermissions $permissions) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $panel, string $group): Response
    {
        $write = ! in_array($request->method(), ['GET', 'HEAD'], true);

        if (! $this->permissions->allows($request, $panel, $group, $write)) {
            $message = __('Your team role does not allow this. Ask the owner for access.');

            if ($request->is('api/*')) {
                return ApiResponse::error('forbidden', $message, 403);
            }

            abort(403, $message);
        }

        return $next($request);
    }
}
