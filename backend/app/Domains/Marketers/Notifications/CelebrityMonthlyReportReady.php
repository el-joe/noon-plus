<?php

namespace App\Domains\Marketers\Notifications;

use App\Domains\Marketers\Models\CelebrityMonthlyReport;
use App\Domains\Panels\Support\Panel;
use App\Shared\Notifications\Email\Attachments\CelebrityReportAttachment;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\EventEmail;

/**
 * FR-ANL-2: the celebrity monthly report by email (catalogue email
 * `marketer.celebrity_report`), in the marketer's language, with the PDF
 * attached and a link to Creator Studio.
 */
class CelebrityMonthlyReportReady extends EventEmail
{
    public function __construct(public CelebrityMonthlyReport $report, public string $countryCode)
    {
        parent::__construct('marketer.celebrity_report', [
            'month' => (string) $report->period,
            'earnings' => EmailValue::money((string) $report->total_earnings, (string) $report->currency),
        ], [
            'country' => $countryCode,
            'panel' => 'marketer',
            'url' => Panel::find('marketer')->url('/reports/'.$report->id),
            'entity_type' => class_basename($report),
            'entity_id' => (string) $report->id,
            'attachments' => [[CelebrityReportAttachment::class, ['report' => $report->id]]],
        ]);
    }
}
