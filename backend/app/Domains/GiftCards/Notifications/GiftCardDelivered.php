<?php

namespace App\Domains\GiftCards\Notifications;

use App\Shared\Notifications\Email\EmailLinks;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\EventEmail;
use DateTimeInterface;

/**
 * The gift card email to the recipient (`gift_card.received`, sensitive:
 * the code is never stored in the email log) — the only place the code appears.
 */
class GiftCardDelivered extends EventEmail
{
    public function __construct(
        public string $code,
        public string $amount,
        public ?string $senderName,
        public ?string $personalMessage,
        public DateTimeInterface|string $expiresOn,
        ?int $giftCardId = null,
        ?string $recipientLocale = null,
    ) {
        $locale = $recipientLocale ?? app()->getLocale();

        parent::__construct('gift_card.received', [
            'sender' => $senderName ?? EmailValue::t('Someone'),
            'amount' => $amount,
            'code' => $code,
            'message' => $personalMessage === null ? '' : '“'.$personalMessage.'”',
            'expires' => EmailValue::date($expiresOn),
        ], array_filter([
            'blocks' => [['type' => 'code', 'value' => $code]],
            'url' => app(EmailLinks::class)->storefront('account/gift-cards', $locale),
            'locale' => $locale,
            'entity_type' => $giftCardId === null ? null : 'GiftCard',
            'entity_id' => $giftCardId === null ? null : (string) $giftCardId,
        ]));
    }
}
