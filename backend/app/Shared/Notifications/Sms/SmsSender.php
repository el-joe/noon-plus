<?php

namespace App\Shared\Notifications\Sms;

/**
 * Sends one text message (SMS). The driver is `nawy.sms.driver`:
 * `log` (development: the message is written to the log, the code masked
 * outside `local`), `array` (tests: kept in memory, read with
 * {@see ArraySmsSender::sent()}). A live provider driver needs the owner's
 * SMS provider account and its credentials (see .env.example, NAWY_SMS_*).
 */
interface SmsSender
{
    /**
     * @param  string  $to  E.164 number (+9665…)
     */
    public function send(string $to, string $message): void;
}
