<?php

namespace App\Shared\Notifications\Email\Attachments;

use App\Domains\Marketers\Models\CelebrityMonthlyReport;
use App\Domains\Marketers\Support\CelebrityReports;

/**
 * The celebrity monthly report PDF (`report` = report id).
 */
class CelebrityReportAttachment implements EmailAttachment
{
    public function __construct(private CelebrityReports $reports) {}

    public function build(array $arguments): ?array
    {
        $report = CelebrityMonthlyReport::query()->find($arguments['report'] ?? null);

        return $report === null ? null : ['data' => $this->reports->pdf($report), 'name' => $report->fileName(), 'mime' => 'application/pdf'];
    }
}
