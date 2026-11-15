<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Identity\Actions\ConsumeEmailLoginCode;
use App\Domains\Identity\Actions\RecordLoginEvent;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
use App\Domains\Panels\Support\Panel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Second sign-in step for panel accounts with two-step sign-in turned on.
 */
class TwoFactorChallengeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $panel = Panel::find($request->route('panel'));
        $pending = $this->pendingUser($request, $panel);

        if ($pending === null) {
            return redirect()->route($panel->route('login'));
        }

        return view('panels.auth.two-factor', [
            'panelName' => $panel->name,
            'action' => route($panel->route('two-factor.store')),
            'maskedEmail' => preg_replace('/(?<=.).(?=[^@]*@)/u', '•', $pending->email),
        ]);
    }

    public function store(
        Request $request,
        ConsumeEmailLoginCode $consumeCode,
        RecordLoginEvent $recordLoginEvent,
        PanelLoginController $loginController,
    ): RedirectResponse {
        $panel = Panel::find($request->route('panel'));
        $user = $this->pendingUser($request, $panel);

        if ($user === null) {
            return redirect()->route($panel->route('login'));
        }

        $code = $request->validate([
            'code' => ['required', 'string', 'digits:'.config('nawy.customer_auth.email_code_length')],
        ])['code'];

        try {
            $consumeCode->handle($user->email, $code, 'panel_2fa');
        } catch (EmailLoginCodeRejected $rejected) {
            $recordLoginEvent->handle(
                $request,
                $panel->key,
                $rejected->reason === EmailLoginCodeRejected::LOCKED ? LoginOutcome::Locked : LoginOutcome::WrongCode,
                $user,
            );

            throw ValidationException::withMessages(['code' => $rejected->getMessage()]);
        }

        $remember = (bool) $request->session()->pull('two_factor.remember', false);
        $request->session()->forget('two_factor');

        return $loginController->startSession($request, $panel, $user, $remember);
    }

    private function pendingUser(Request $request, Panel $panel): ?User
    {
        $pending = $request->session()->get('two_factor');

        if (! is_array($pending) || $pending['panel'] !== $panel->key) {
            return null;
        }

        return User::query()->find($pending['user_id']);
    }
}
