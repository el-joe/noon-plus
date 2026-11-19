<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Enums\ReportStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedReport;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Settings\VerticalSettings;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;

/**
 * Report ad → admin abuse queue (MP §12): open → reviewing → actioned
 * (the ad is archived) | dismissed. One open report per reporter and ad.
 */
class ClassifiedReports
{
    public function __construct(private VerticalSettings $settings, private ClassifiedAdMachine $ads, private StateMachine $machine) {}

    public function report(ClassifiedAd $ad, ?int $reporterUserId, string $reason, ?string $details): ClassifiedReport
    {
        if (! in_array($reason, (array) $this->settings->get('classified.report_reasons'), true)) {
            throw new TransitionRefused(__('Choose a reason.'));
        }

        if ($reporterUserId !== null && ClassifiedReport::query()->where('classified_ad_id', $ad->id)->where('reporter_user_id', $reporterUserId)->whereIn('status', [ReportStatus::Open, ReportStatus::Reviewing])->exists()) {
            throw new TransitionRefused(__('You already reported this ad.'));
        }

        $report = new ClassifiedReport(['classified_ad_id' => $ad->id, 'reporter_user_id' => $reporterUserId, 'reason' => $reason, 'details' => $details === null ? null : mb_substr($details, 0, 1000)]);
        $report->forceFill(['status' => ReportStatus::Open])->save();
        $this->machine->created($report, $reporterUserId === null ? ActorType::System : ActorType::Customer, $reporterUserId, $reason);

        return $report;
    }

    public function decide(ClassifiedReport $report, ReportStatus $to, int $adminUserId, ?string $note = null): ClassifiedReport
    {
        if (! in_array($to, $report->status->allowedNext(), true)) {
            throw new TransitionRefused(__('This report was already handled.'));
        }

        $this->machine->move($report, $to, ActorType::Admin, $adminUserId, $note, ['resolution_note' => $note, 'handled_by_user_id' => $adminUserId]);

        if ($to === ReportStatus::Actioned) {
            $ad = $report->ad;

            if (in_array(ClassifiedAdStatus::Archived, $ad->status->allowedNext(), true)) {
                $this->ads->archive($ad, ActorType::Admin, $adminUserId, 'Abuse report #'.$report->id.': '.$report->reason);
            }

            /*
            | QC-053: the ad's other open reports close through the machine too
            | (one history row each, actor admin).
            */
            foreach (ClassifiedReport::query()->where('classified_ad_id', $ad->id)->whereIn('status', [ReportStatus::Open, ReportStatus::Reviewing])->get() as $sibling) {
                $note = 'Closed with report #'.$report->id;
                $this->machine->move($sibling, ReportStatus::Actioned, ActorType::Admin, $adminUserId, $note, ['handled_by_user_id' => $adminUserId, 'resolution_note' => $note]);
            }
        }

        return $report;
    }
}
