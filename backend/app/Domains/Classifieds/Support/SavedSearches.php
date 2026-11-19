<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Models\SavedSearch;
use App\Domains\Classifieds\Notifications\SavedSearchMatches;
use App\Models\User;
use App\Shared\Notifications\Email\EmailLinks;

/**
 * Saved searches and their alerts (MP v2 §14b, catalogue Appendix A): the
 * hourly job checks each active search for ads approved since it last
 * looked; `instant` searches are told every hour, `daily` ones at most once
 * a day (matches keep accumulating until then).
 */
class SavedSearches
{
    public function __construct(private ClassifiedSearch $search) {}

    /**
     * @return int alerts sent
     */
    public function alertDue(): int
    {
        $sent = 0;

        SavedSearch::query()->where('status', 'active')->orderBy('id')->each(function (SavedSearch $saved) use (&$sent): void {
            if ($saved->alert_frequency === 'daily' && $saved->last_alerted_at !== null && $saved->last_alerted_at->greaterThan(now()->subDay())) {
                return;
            }

            $since = $saved->checked_until ?? $saved->created_at;
            $matches = $this->search->query($saved->filters ?? [])->where('approved_at', '>', $since)->where('approved_at', '<=', now())->limit(50)->get();
            $saved->forceFill(['checked_until' => now()])->save();

            if ($matches->isEmpty()) {
                return;
            }

            $customer = User::query()->find($saved->customer_user_id);
            $customer?->notify(new SavedSearchMatches($saved->name, $matches->count(), $matches->pluck('title')->all(),
                app(EmailLinks::class)->storefront('account/saved-searches', $customer->preferredLocale())));
            $saved->forceFill(['last_alerted_at' => now()])->save();
            $sent++;
        });

        return $sent;
    }
}
