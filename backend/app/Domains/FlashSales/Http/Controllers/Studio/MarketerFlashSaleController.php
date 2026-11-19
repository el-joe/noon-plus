<?php

namespace App\Domains\FlashSales\Http\Controllers\Studio;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\FlashSales\Models\FlashSaleMarketerInvite;
use App\Domains\FlashSales\Support\FlashSaleMarketerInvites;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Creator Studio → Flash sales (lifecycle catalogue §4.10 marketer invite
 * sub-machine): the flash sales the marketer is invited to, with dates and
 * bonus, and accept / decline while the event has not ended.
 */
class MarketerFlashSaleController extends Controller
{
    public function __construct(private MarketerWorkspace $workspace, private FlashSaleMarketerInvites $invites) {}

    public function index(CountryClock $clock): View
    {
        return view('panels.marketer.flash-sales', [
            'invites' => FlashSaleMarketerInvite::query()->where('marketer_profile_id', $this->workspace->marketer()->id)
                ->with('event')->latest('id')->paginate(20),
            'clock' => $clock,
        ]);
    }

    public function respond(Request $request, int $invite, string $answer): RedirectResponse
    {
        $invite = FlashSaleMarketerInvite::query()->where('marketer_profile_id', $this->workspace->marketer()->id)->findOrFail($invite);

        try {
            $this->invites->respond($invite, $answer === 'accept', $request->user()->id);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', $answer === 'accept' ? __('Invitation accepted.') : __('Invitation declined.'));
    }
}
