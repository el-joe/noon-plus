<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\LoginMethod;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Identity\Support\SecurityEmails;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecordLoginEvent
{
    public function __construct(private SecurityEmails $securityEmails) {}

    /**
     * A successful sign-in from a device / network not seen before also emails the account (`auth.new_device`).
     */
    public function handle(Request $request, string $channel, LoginOutcome $outcome, ?User $user = null, ?string $email = null, ?LoginMethod $method = null): void
    {
        $event = LoginEvent::query()->create([
            'user_id' => $user?->id,
            'email' => Str::lower((string) ($email ?? $user?->email)),
            'channel' => $channel,
            'method' => $method,
            'outcome' => $outcome,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);

        if ($outcome === LoginOutcome::Succeeded && $user !== null) {
            $this->securityEmails->newDevice($event, $user);
        }
    }
}
