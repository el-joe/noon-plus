<?php

namespace App\Domains\Marketers\Http\Controllers\Studio;

use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\Validation\CountryPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Creator Studio → Profile & socials (SRS §4.4 "Profile"): the public
 * profile shown in the creator directory and boutique — name, bio, the
 * WhatsApp number invitations go to and social channels.
 */
class MarketerProfileController extends Controller
{
    public const NETWORKS = ['instagram', 'tiktok', 'youtube', 'snapchat', 'x', 'facebook'];

    public function __construct(private MarketerWorkspace $workspace) {}

    public function show(): View
    {
        abort_unless($this->workspace->has(), 403);

        return view('panels.marketer.profile', ['marketer' => $this->workspace->marketer(), 'networks' => self::NETWORKS]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($this->workspace->has(), 403);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'bio' => ['nullable', 'string', 'max:160'],
            'whatsapp_phone' => ['required', 'string', 'max:24', new CountryPhone],
            'followers' => ['nullable', 'integer', 'min:0'],
            'socials' => ['nullable', 'array'],
            'socials.*' => ['nullable', 'string', 'max:120'],
        ]);
        $socials = collect($data['socials'] ?? [])->only(self::NETWORKS)->filter()
            ->map(fn (string $handle, string $network): array => ['network' => $network, 'handle' => $handle])->values()->all();

        $this->workspace->marketer()->forceFill([
            'display_name' => $data['display_name'],
            'bio' => $data['bio'] ?? null,
            'whatsapp_phone' => CountryPhone::normalize($data['whatsapp_phone']),
            'followers' => (int) ($data['followers'] ?? 0),
            'socials' => $socials === [] ? null : $socials,
        ])->save();

        return back()->with('status', __('Profile saved.'));
    }
}
