<?php

namespace App\Shared\Notifications\Push;

/**
 * Sends a push notification to one device (`nawy.push.driver`): `log`
 * (development), `array` (tests) or `fcm` (Firebase Cloud Messaging HTTP
 * v1, which needs the owner's Firebase project id and service-account
 * key: NAWY_FCM_PROJECT_ID, NAWY_FCM_CREDENTIALS).
 */
interface PushSender
{
    /**
     * @return bool false when the token is no longer valid (the caller forgets it)
     */
    public function send(PushMessage $message): bool;
}
