<?php

namespace App\Domains\LiveMedia\Support;

use App\Domains\LiveMedia\Contracts\LiveMediaProvider;

/**
 * In-memory provider for tests and local work without LiveKit credentials.
 */
class FakeLiveMediaProvider implements LiveMediaProvider
{
    /**
     * @var array<string, string>
     */
    public array $rooms = [];

    public function createRoom(string $name, int $maxParticipants = 0): string
    {
        return $this->rooms[$name] ??= 'RM_fake_'.substr(sha1($name), 0, 10);
    }

    public function deleteRoom(string $name): void
    {
        unset($this->rooms[$name]);
    }

    public function accessToken(string $room, string $identity, string $displayName, bool $canPublish, int $ttlMinutes): string
    {
        return 'fake.'.base64_encode(json_encode(compact('room', 'identity', 'canPublish'), JSON_THROW_ON_ERROR));
    }

    public function clientUrl(): ?string
    {
        return 'wss://live.fake.test';
    }
}
