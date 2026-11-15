<?php

namespace App\Domains\Panels\Http\Controllers\Api;

use App\Domains\Identity\Actions\RecordLoginEvent;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Support\TokenScopes;
use App\Domains\Panels\Support\OpsWorkspaces;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Shared sign-in of the ops apps (vendor, marketer, supervisor, travel
 * agency; Flutter, Sanctum): email + password for one role. The token only
 * carries the ability `ops:{role}`; the user needs that role's workspace in
 * this country. Login events are recorded on the `ops_app` channel.
 */
class OpsAuthController extends Controller
{
    public function __construct(private OpsWorkspaces $workspaces) {}

    public function login(Request $request, RecordLoginEvent $recordLoginEvent): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::in(OpsWorkspaces::ROLES)],
        ]);
        $user = User::query()->where('email', Str::lower($data['email']))->first();

        if ($user === null || ! Hash::check($data['password'], (string) $user->password) || $user->status !== 'active') {
            $recordLoginEvent->handle($request, 'ops_app', LoginOutcome::WrongCredentials, email: $data['email']);

            return ApiResponse::error('invalid_credentials', __('The email or password is not right.'), 422);
        }

        $workspace = $this->workspaces->resolve($user, $data['role']);

        if ($workspace === null) {
            $recordLoginEvent->handle($request, 'ops_app', LoginOutcome::NoPanelAccess, $user);

            return ApiResponse::error('no_workspace', __('This account has no :role workspace in this country.', ['role' => str_replace('_', ' ', $data['role'])]), 403);
        }

        $recordLoginEvent->handle($request, 'ops_app', LoginOutcome::Succeeded, $user);

        return ApiResponse::success([
            'token' => $user->createToken($data['device_name'], [TokenScopes::OPS_PREFIX.$data['role']], app(TokenScopes::class)->expiresAt('ops'))->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'workspace' => $this->workspaces->summary($data['role'], $workspace),
        ]);
    }
}
