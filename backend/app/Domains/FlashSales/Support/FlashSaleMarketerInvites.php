<?php

namespace App\Domains\FlashSales\Support;

use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleInviteStatus;
use App\Domains\FlashSales\Enums\MarketerBonusType;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleMarketerInvite;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Support\PanelNotifications;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;

/**
 * Flash-sale marketer invites (lifecycle catalogue §4.10 sub-machine):
 * opening an event for submissions invites every active marketer of the
 * country (admins can also invite one marketer at any time before the
 * event ends); the marketer accepts or declines in Creator Studio while
 * the event has not ended. When the event has its own marketer bonus
 * (percent of the commission basis, or a fixed amount per unit), only
 * marketers who accepted earn it on conversions sold at the flash price
 * ({@see self::bonusFor()}); events without one keep the country's
 * `flash_sale.marketer_bonus` percent for every marketer.
 */
class FlashSaleMarketerInvites
{
    public function __construct(private StateMachine $machine, private MoneyFactory $money) {}

    public function invite(FlashSaleEvent $event, MarketerProfile $marketer, ActorType $actorType, ?int $actorUserId = null): FlashSaleMarketerInvite
    {
        if (in_array($event->status, [FlashSaleEventStatus::Ended, FlashSaleEventStatus::Cancelled], true)) {
            throw new TransitionRefused(__('Marketers cannot be invited to a flash sale that is over.'));
        }

        if (! $marketer->isActive()) {
            throw new TransitionRefused(__('Only active marketers can be invited.'));
        }

        $invite = FlashSaleMarketerInvite::query()->firstOrCreate(
            ['flash_sale_event_id' => $event->id, 'marketer_profile_id' => $marketer->id],
            ['status' => FlashSaleInviteStatus::Pending, 'invited_by' => $actorUserId],
        );

        if ($invite->wasRecentlyCreated) {
            $this->machine->created($invite, $actorType, $actorUserId);

            if ($marketer->user_id !== null) {
                app(PanelNotifications::class)->notify((int) $marketer->user_id, 'marketer', 'flash_sale.invited',
                    __('You are invited to promote :event', ['event' => $event->display_name ?: $event->name]),
                    __('Accept to earn the flash-sale bonus on sales at the flash price.'), '/flash-sales');
            }
        }

        return $invite;
    }

    /**
     * Invite every active marketer of the country (event opened for submissions).
     */
    public function inviteAllActive(FlashSaleEvent $event, ActorType $actorType, ?int $actorUserId = null): int
    {
        $invited = 0;

        MarketerProfile::query()->where('global_status', MarketerStatus::Active)->orderBy('id')
            ->each(function (MarketerProfile $marketer) use ($event, $actorType, $actorUserId, &$invited): void {
                $invited += $this->invite($event, $marketer, $actorType, $actorUserId)->wasRecentlyCreated ? 1 : 0;
            });

        return $invited;
    }

    public function respond(FlashSaleMarketerInvite $invite, bool $accept, int $userId): FlashSaleMarketerInvite
    {
        $event = $invite->event()->firstOrFail();

        if (in_array($event->status, [FlashSaleEventStatus::Ended, FlashSaleEventStatus::Cancelled], true)) {
            throw new TransitionRefused(__('This flash sale is over.'));
        }

        return $this->machine->move($invite, $accept ? FlashSaleInviteStatus::Accepted : FlashSaleInviteStatus::Declined, ActorType::Marketer, $userId, null, ['responded_at' => now()]);
    }

    /**
     * The event bonus of a conversion sold at the flash price, or null when
     * the event has no bonus of its own (the country percent applies).
     */
    public function bonusFor(FlashSaleEvent $event, int $marketerProfileId, Money $basis, int $quantity): ?Money
    {
        if ($event->marketer_bonus_type === null || $event->marketer_bonus_value === null) {
            return null;
        }

        $currency = $basis->getCurrency()->getCurrencyCode();
        $accepted = FlashSaleMarketerInvite::query()->where('flash_sale_event_id', $event->id)->where('marketer_profile_id', $marketerProfileId)
            ->where('status', FlashSaleInviteStatus::Accepted)->exists();

        if (! $accepted) {
            return $this->money->zero($currency);
        }

        return $event->marketer_bonus_type === MarketerBonusType::Percent
            ? $this->money->percentageOf($basis, (string) $event->marketer_bonus_value)
            : $this->money->of((string) $event->marketer_bonus_value, $currency)->multipliedBy($quantity);
    }
}
