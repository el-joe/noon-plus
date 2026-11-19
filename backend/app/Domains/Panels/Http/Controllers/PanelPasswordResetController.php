<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Identity\Support\SecurityEmails;
use App\Domains\Panels\Notifications\PanelPasswordResetNotification;
use App\Domains\Panels\Support\Panel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Forgot / reset password for every business panel (QC-006). The link is
 * mailed only to accounts with access to that panel, and the answer is the
 * same either way. A reset never signs in: the person signs in again, so
 * two-step sign-in (when on) still applies.
 */
class PanelPasswordResetController extends Controller
{
    public function create(Request $request): View
    {
        $panel = Panel::find($request->route('panel'));

        return view('panels.auth.forgot-password', [
            'panelName' => $panel->name,
            'action' => route($panel->route('password.email')),
            'loginUrl' => route($panel->route('login')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $panel = Panel::find($request->route('panel'));
        $email = Str::lower(trim($request->validate(['email' => ['required', 'string', 'email', 'max:255']])['email']));
        $user = User::query()->where('email', $email)->first();

        if ($user !== null && $panel->allows($user)) {
            $token = PasswordBroker::broker()->createToken($user);
            $user->notify(new PanelPasswordResetNotification(
                route($panel->route('password.reset'), ['token' => $token, 'email' => $email]),
                $panel->name,
                (int) config('auth.passwords.users.expire'),
                $panel->key,
            ));
        }

        return back()->with('status', __('If this email has access to the panel, we have sent a link to reset the password.'));
    }

    public function edit(Request $request, string $token): View
    {
        $panel = Panel::find($request->route('panel'));

        return view('panels.auth.reset-password', [
            'panelName' => $panel->name,
            'action' => route($panel->route('password.update')),
            'token' => $token,
            'email' => (string) $request->query('email'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $panel = Panel::find($request->route('panel'));
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(8)],
        ]);
        $user = User::query()->where('email', Str::lower(trim($data['email'])))->first();

        if ($user === null || ! $panel->allows($user) || ! PasswordBroker::broker()->tokenExists($user, $data['token'])) {
            throw ValidationException::withMessages(['email' => __('This reset link is invalid or has expired. Ask for a new one.')]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
        PasswordBroker::broker()->deleteToken($user);
        app(SecurityEmails::class)->passwordChanged($user, $panel->key);

        return redirect()->route($panel->route('login'))->with('status', __('Your password has been reset. Sign in to continue.'));
    }
}
