<?php

namespace App\Domains\LiveMedia\Contracts;

/**
 * Live media SFU adapter (ADR 11; settings `streams.provider`): rooms for
 * admin live broadcasts and signed access tokens for the admin studio
 * (publisher) and viewers (subscribe only).
 */
interface LiveMediaProvider
{
    /**
     * Create (or reuse) a room; returns the provider's room id.
     */
    public function createRoom(string $name, int $maxParticipants = 0): string;

    /**
     * Close a room and disconnect everyone in it.
     */
    public function deleteRoom(string $name): void;

    /**
     * A signed join token for one participant.
     */
    public function accessToken(string $room, string $identity, string $displayName, bool $canPublish, int $ttlMinutes): string;

    /**
     * The URL clients connect to (WebSocket / signalling).
     */
    public function clientUrl(): ?string;
}
