<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Identity\Actions\RecordLoginEvent;
use App\Domains\Identity\Actions\RequestEmailLoginCode;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
use App\Domains\Panels\Support\Panel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Email + password sign-in shared by every business panel (MASTER_PLAN ADR 5).
 * Accounts with two-step sign-in get an emailed code before the session starts.
 */
class PanelLoginController extends Controller
{
    public function __construct(private RecordLoginEvent $recordLoginEvent) {}

    public function show(Request $request): View
    {
        $panel = Panel::find($request->route('panel'));

        return view('panels.auth.login', [
            'panelName' => $panel->name,
            'action' => route($panel->route('login.store')),
        ]);
    }

    public function store(Request $request, RequestEmailLoginCode $requestCode): RedirectResponse
    {
        $panel = Panel::find($request->route('panel'));
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::validate($credentials)) {
            $this->recordLoginEvent->handle($request, $panel->key, LoginOutcome::WrongCredentials, email: $credentials['email']);

            throw ValidationException::withMessages(['email' => __('These details do not match our records.')]);
        }

        $user = User::query()->where('email', $credentials['email'])->sole();

        if (! $panel->allows($user)) {
            $this->recordLoginEvent->handle($request, $panel->key, LoginOutcome::NoPanelAccess, $user);

            throw ValidationException::withMessages(['email' => __('Your account has no access to this panel.')]);
        }

        if ($user->two_factor_enabled) {
            try {
                $requestCode->handle($user->email);
            } catch (EmailLoginCodeRejected $rejected) {
                throw ValidationException::withMessages(['email' => $rejected->getMessage()]);
            }

            $request->session()->put('two_factor', [
                'user_id' => $user->id,
                'panel' => $panel->key,
                'remember' => $request->boolean('remember'),
            ]);
            $this->recordLoginEvent->handle($request, $panel->key, LoginOutcome::SecondStepSent, $user);

            return redirect()->route($panel->route('two-factor'));
        }

        return $this->startSession($request, $panel, $user, $request->boolean('remember'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $panel = Panel::find($request->route('panel'));

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($panel->route('login'));
    }

    /**
     * Sign the user in to the panel after every check has passed.
     */
    public function startSession(Request $request, Panel $panel, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->recordLoginEvent->handle($request, $panel->key, LoginOutcome::Succeeded, $user);

        return redirect()->intended(route($panel->route('dashboard')));
    }
}
