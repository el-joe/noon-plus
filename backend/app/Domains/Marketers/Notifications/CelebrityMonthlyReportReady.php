<?php

namespace App\Domains\Marketers\Notifications;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Models\CelebrityMonthlyReport;
use App\Domains\Marketers\Support\CelebrityReports;
use App\Domains\Panels\Support\Panel;
use App\Shared\Money\MoneyFormatter;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-ANL-2: the celebrity monthly report by email, in the marketer's
 * language (the user's preferred locale), with the PDF attached and a link
 * to Creator Studio.
 */
class CelebrityMonthlyReportReady extends Notification
{
    public function __construct(public CelebrityMonthlyReport $report, public string $countryCode) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $country = Country::query()->where('code', $this->countryCode)->firstOrFail();

        return app(CountryContext::class)->runAs($country, function (): MailMessage {
            $earnings = app(MoneyFormatter::class)->format((string) $this->report->total_earnings, $this->report->currency);
            $pdf = app(CelebrityReports::class)->pdf($this->report);

            return (new MailMessage)
                ->subject(__('Your Nawy creator report for :period', ['period' => $this->report->period]))
                ->line(__('Your report for :period is ready: :orders orders, :earnings earned.', ['period' => $this->report->period, 'orders' => $this->report->orders_count, 'earnings' => $earnings]))
                ->action(__('Open in Creator Studio'), Panel::find('marketer')->url('/reports/'.$this->report->id))
                ->attachData($pdf, $this->report->fileName(), ['mime' => 'application/pdf']);
        });
    }
}
