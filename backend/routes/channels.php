<?php

use App\Broadcasting\CountryOpsChannel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('country.{code}.ops', CountryOpsChannel::class);

/*
| D18: native WebRTC live signalling. The host studio (admins who may manage
| live media) listens on `live.{slug}.host`; a viewer's own channel
| `live.{slug}.peer.{peer}` is authorised only by its peer token through the
| API's `broadcasting/auth` — never through a panel session.
*/
Broadcast::channel('live.{slug}.host', fn (User $user): bool => $user->can('live_media.manage'));
Broadcast::channel('live.{slug}.peer.{peer}', fn (): bool => false);
