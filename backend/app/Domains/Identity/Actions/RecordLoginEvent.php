<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecordLoginEvent
{
    public function handle(Request $request, string $channel, LoginOutcome $outcome, ?User $user = null, ?string $email = null): void
    {
        LoginEvent::query()->create([
            'user_id' => $user?->id,
            'email' => Str::lower((string) ($email ?? $user?->email)),
            'channel' => $channel,
            'outcome' => $outcome,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);
    }
}
