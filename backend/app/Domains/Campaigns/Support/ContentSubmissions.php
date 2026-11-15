<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Enums\ContentStatus;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\ContentSubmission;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;

/**
 * Content submission and approval (MP §8): an accepted marketer submits a
 * post link; the campaign owner or an admin approves or rejects it with a
 * reason.
 */
class ContentSubmissions
{
    public function __construct(private StateMachine $machine) {}

    public function submit(CampaignInvitation $invitation, string $platform, string $url, ?string $caption = null, ?int $userId = null): ContentSubmission
    {
        if ($invitation->status !== InvitationStatus::Accepted) {
            throw new TransitionRefused(__('Accept the invitation before submitting content.'));
        }

        $submission = ContentSubmission::query()->create([
            'campaign_id' => $invitation->campaign_id,
            'invitation_id' => $invitation->id,
            'marketer_profile_id' => $invitation->marketer_profile_id,
            'platform' => $platform,
            'url' => $url,
            'caption' => $caption,
            'status' => ContentStatus::Submitted,
        ]);
        $this->machine->created($submission, ActorType::Marketer, $userId);

        return $submission;
    }

    public function approve(ContentSubmission $submission, ActorType $actor, ?int $userId): ContentSubmission
    {
        return $this->machine->move($submission, ContentStatus::Approved, $actor, $userId, null, ['reviewed_by_user_id' => $userId, 'reviewed_at' => now()]);
    }

    public function reject(ContentSubmission $submission, ActorType $actor, ?int $userId, string $reason): ContentSubmission
    {
        if (trim($reason) === '') {
            throw new TransitionRefused(__('Give a reason for the rejection.'));
        }

        return $this->machine->move($submission, ContentStatus::Rejected, $actor, $userId, $reason, ['reviewed_by_user_id' => $userId, 'reviewed_at' => now(), 'rejection_reason' => mb_substr($reason, 0, 255)]);
    }
}
