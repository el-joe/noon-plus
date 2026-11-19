<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Identity\Support\SecurityEmails;
use App\Domains\Panels\Support\Panel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Set a panel password from a signed, expiring link mailed to the account
 * (QA-002 / QA-003): an existing account that gained a workspace — e.g. a
 * customer who signs in with email codes only — sets its own password; no
 * one else's password is ever applied to it.
 */
class PasswordSetupController extends Controller
{
    public function show(Request $request, int $user): View
    {
        $panel = Panel::find($request->route('panel'));
        $account = User::query()->findOrFail($user);

        return view('panels.auth.password-setup', [
            'panelName' => $panel->name,
            'email' => $account->email,
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, int $user): RedirectResponse
    {
        $panel = Panel::find($request->route('panel'));
        $account = User::query()->findOrFail($user);
        $data = $request->validate(['password' => ['required', 'string', 'confirmed', Password::min(8)]]);
        $account->forceFill(['password' => Hash::make($data['password']), 'email_verified_at' => $account->email_verified_at ?? now()])->save();
        app(SecurityEmails::class)->passwordChanged($account, $panel->key);

        return redirect()->route($panel->route('login'))->with('status', __('Your password is set. Sign in to continue.'));
    }
}
