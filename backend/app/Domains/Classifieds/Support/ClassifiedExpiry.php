<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Notifications\ClassifiedRenewalReminder;
use App\Models\User;
use App\Shared\Settings\VerticalSettings;

/**
 * Classified expiry job (MP §11, lifecycle catalogue §5.1): first the
 * renewal reminders (`classified.renewal_reminder_days`, once per
 * threshold), then live / paused ads past `expires_at` expire.
 */
class ClassifiedExpiry
{
    public function __construct(private ClassifiedAdMachine $ads, private VerticalSettings $settings) {}

    /**
     * @return array{reminded: int, expired: int}
     */
    public function run(): array
    {
        $reminded = 0;
        $thresholds = array_map('intval', (array) $this->settings->get('classified.renewal_reminder_days'));
        rsort($thresholds);

        ClassifiedAd::query()->where('status', ClassifiedAdStatus::Active)->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(max($thresholds ?: [0])))
            ->orderBy('id')
            ->each(function (ClassifiedAd $ad) use ($thresholds, &$reminded): void {
                $daysLeft = (int) ceil(now()->diffInMinutes($ad->expires_at) / 1440);
                $sent = $ad->reminders_sent ?? [];
                $due = collect($thresholds)->filter(fn (int $threshold): bool => $daysLeft <= $threshold && ! in_array($threshold, $sent, true));

                if ($due->isEmpty()) {
                    return;
                }

                User::query()->find($ad->owner_user_id)?->notify(new ClassifiedRenewalReminder($ad->number, $ad->title, max(1, $daysLeft)));
                $ad->forceFill(['reminders_sent' => array_values(array_unique(array_merge($sent, $due->all())))])->save();
                $reminded++;
            });

        return ['reminded' => $reminded, 'expired' => $this->ads->expireDue()];
    }
}
