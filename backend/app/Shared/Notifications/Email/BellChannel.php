<?php

namespace App\Shared\Notifications\Email;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Customers\Support\CustomerNotifications;
use App\Domains\Panels\Support\PanelNotifications;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The `bell` channel of catalogue events: the in-app notification centre of
 * the customer app / website or the business panel (which also pushes to
 * the user's devices). Title = rendered subject, body = first paragraph.
 * Never fails the job: the email is what matters.
 */
class BellChannel
{
    public function send(object $notifiable, EventEmail $notification): void
    {
        if (! $notifiable instanceof User || $notification->country() === null) {
            return;
        }

        try {
            $country = Country::query()->where('code', $notification->country())->first();

            if ($country === null) {
                return;
            }

            app(CountryContext::class)->runAs($country, function () use ($notifiable, $notification): void {
                $rendered = $notification->render($notifiable);

                if ($rendered === null) {
                    return;
                }

                $paragraphs = preg_split('/\n{2,}/', $rendered->text) ?: [];
                $body = trim($paragraphs[3] ?? $paragraphs[2] ?? '');
                $panel = EmailLinks::panelFor(EmailCatalogue::get($notification->event)['actor'], $notification->options['panel'] ?? null);
                $link = $notification->options['bell_link'] ?? null;

                if ($panel === null) {
                    app(CustomerNotifications::class)->notify($notifiable->id, $notification->event, $rendered->subject, mb_substr($body, 0, 1000), $link, ['event' => $notification->event]);
                } else {
                    app(PanelNotifications::class)->notify($notifiable->id, $panel === 'partner' ? 'partner' : $panel, $notification->event, $rendered->subject, mb_substr($body, 0, 1000), $link);
                }
            });
        } catch (Throwable $exception) {
            Log::warning('Email bell channel failed', ['event' => $notification->event, 'error' => $exception->getMessage()]);
        }
    }
}
