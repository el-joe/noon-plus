<?php

namespace App\Domains\Onboarding\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Support\Documents;
use App\Domains\Onboarding\Enums\ApplicationStatus;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Notifications\RequestNotification;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\StateTransition;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\Notification;

/**
 * Admin application review (SRS §4.1.5 "Applications", Figma approvals
 * queue 96:2483): assign to me, start review, approve (creates the tenant
 * and its membership and mails the welcome), reject with a reason, or ask
 * for changes to named steps and documents. Every decision is in the
 * application's history with the admin and a reason (audit trail).
 */
class ApplicationReview
{
    public function __construct(
        private StateMachine $machine,
        private TenantProvisioner $provisioner,
        private Documents $documents,
        private ApplicationWizard $wizard,
        private CountryContext $countryContext,
        private AcquirerReferrals $referrals,
    ) {}

    public function assign(PartnerApplication $application, int $adminUserId): PartnerApplication
    {
        $application->forceFill(['assigned_to_user_id' => $adminUserId])->save();
        StateTransition::query()->create([
            'subject_type' => $application->transitionSubject(),
            'subject_id' => $application->id,
            'from_status' => $application->status->value,
            'to_status' => $application->status->value,
            'actor_type' => ActorType::Admin,
            'actor_user_id' => $adminUserId,
            'reason' => 'Assigned to reviewer',
            'meta' => ['event' => 'assigned', 'assigned_to_user_id' => $adminUserId],
        ]);

        return $application;
    }

    /**
     * FR-FIN-6: record (or clear) the account manager who brought the
     * partner in. Allowed until the application is decided; approval credits
     * the new store to them ({@see approve()}).
     */
    public function setAcquirer(PartnerApplication $application, ?int $acquirerUserId, int $adminUserId): PartnerApplication
    {
        if (in_array($application->status, [ApplicationStatus::Approved, ApplicationStatus::Rejected, ApplicationStatus::Withdrawn], true)) {
            throw new TransitionRefused(__('The application is already decided; change the acquirer on the seller instead.'));
        }

        if ($acquirerUserId !== null && ! $this->referrals->isAcquirer($acquirerUserId)) {
            throw new TransitionRefused(__('Choose an account manager of this country.'));
        }

        if ($application->acquired_by_user_id === $acquirerUserId) {
            return $application;
        }

        $before = $application->acquired_by_user_id;
        $application->forceFill(['acquired_by_user_id' => $acquirerUserId])->save();
        StateTransition::query()->create([
            'subject_type' => $application->transitionSubject(),
            'subject_id' => $application->id,
            'from_status' => $application->status->value,
            'to_status' => $application->status->value,
            'actor_type' => ActorType::Admin,
            'actor_user_id' => $adminUserId,
            'reason' => $acquirerUserId === null ? 'Acquirer cleared' : 'Acquirer set',
            'meta' => ['event' => 'acquirer', 'from' => $before, 'to' => $acquirerUserId],
        ]);

        return $application;
    }

    public function startReview(PartnerApplication $application, int $adminUserId): PartnerApplication
    {
        return $this->machine->move($application, ApplicationStatus::UnderReview, ActorType::Admin, $adminUserId, null, [
            'assigned_to_user_id' => $application->assigned_to_user_id ?? $adminUserId,
        ]);
    }

    /**
     * Approve: refused while a mandatory document is missing or rejected
     * (ask for changes instead). Pending documents are approved with it.
     */
    public function approve(PartnerApplication $application, int $adminUserId, ?string $note = null): PartnerApplication
    {
        if ($application->email_verified_at === null) {
            throw new TransitionRefused(__('The applicant has not confirmed their email yet. Ask them to enter the code we mailed.'));
        }

        if (! in_array($application->status, ApplicationStatus::inReview(), true)) {
            throw new TransitionRefused(__('Only submitted applications can be approved.'));
        }

        $missing = $this->wizard->missingDocuments($application);

        if ($missing->isNotEmpty()) {
            throw new TransitionRefused(__('Mandatory documents are missing or rejected — request changes instead.'));
        }

        $application->documents()->where('status', DocumentStatus::Pending)->get()
            ->each(fn (Document $document) => $this->documents->approve($document, $adminUserId));

        $tenant = $this->provisioner->provision($application, $adminUserId);

        // FR-FIN-6: the store is credited to the account manager who brought it in.
        if ($application->acquired_by_user_id !== null && $tenant['tenant_type'] === PanelCountries::VENDOR_TENANT) {
            Vendor::query()->whereKey($tenant['tenant_id'])->whereNull('acquired_by_user_id')
                ->update(['acquired_by_user_id' => $application->acquired_by_user_id, 'acquired_at' => now()]);
        }

        return $this->machine->move($application, ApplicationStatus::Approved, ActorType::Admin, $adminUserId, $note, [
            'reviewed_by_user_id' => $adminUserId,
            'decided_at' => now(),
            'decision_reason' => $note,
            'user_id' => $tenant['user']->id,
            'tenant_type' => $tenant['tenant_type'],
            'tenant_id' => $tenant['tenant_id'],
            'password_hash' => null,
        ], ['tenant_type' => $tenant['tenant_type'], 'tenant_id' => $tenant['tenant_id']]);
    }

    public function reject(PartnerApplication $application, int $adminUserId, string $reason): PartnerApplication
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new TransitionRefused(__('Give a reason for the rejection.'));
        }

        $this->machine->move($application, ApplicationStatus::Rejected, ActorType::Admin, $adminUserId, $reason, [
            'reviewed_by_user_id' => $adminUserId,
            'decided_at' => now(),
            'decision_reason' => mb_substr($reason, 0, 1000),
            'password_hash' => null,
        ]);

        $this->mail($application, 'partner_application.rejected', __('Your Nawy application was not approved'), [
            __('We could not approve your :type application.', ['type' => $application->type->label()]),
            __('Reason: :reason', ['reason' => $reason]),
            __('You can apply again at any time.'),
        ]);

        return $application;
    }

    /**
     * Ask for changes: the named steps reopen, named documents are rejected
     * with the note, and the applicant gets a fresh link to continue.
     *
     * @param  array<int, string>  $steps
     * @param  array<int, int>  $documentIds
     */
    public function requestChanges(PartnerApplication $application, int $adminUserId, string $note, array $steps = [], array $documentIds = [], ?string $resumeBaseUrl = null): PartnerApplication
    {
        $note = trim($note);

        if ($note === '') {
            throw new TransitionRefused(__('Tell the applicant what to change.'));
        }

        $steps = array_values(array_intersect($steps, $application->type->dataSteps()));

        if (! in_array($application->status, ApplicationStatus::inReview(), true)) {
            throw new TransitionRefused(__('Only submitted applications can be sent back for changes.'));
        }

        $application->documents()->whereIn('id', $documentIds)->where('status', DocumentStatus::Pending)->get()
            ->each(fn (Document $document) => $this->documents->reject($document, $adminUserId, $note));

        $this->machine->move($application, ApplicationStatus::ChangesRequested, ActorType::Admin, $adminUserId, $note, [
            'reviewed_by_user_id' => $adminUserId,
            'requested_changes' => ['note' => $note, 'steps' => $steps, 'document_ids' => array_values(array_map('intval', $documentIds))],
            'completed_steps' => array_values(array_diff($application->completed_steps ?? [], array_merge($steps, ['agreement']))),
        ], ['steps' => $steps, 'document_ids' => $documentIds]);

        $application->refresh();

        if ($documentIds !== [] && in_array('documents', $application->type->steps(), true) && $this->wizard->missingDocuments($application)->isNotEmpty()) {
            $application->forceFill(['completed_steps' => array_values(array_diff($application->completed_steps, ['documents']))])->save();
        }

        $token = $this->wizard->issueResumeToken($application);
        $link = $resumeBaseUrl === null
            ? $this->wizard->resumeLink($application, $token)
            : rtrim($resumeBaseUrl, '/').'/'.$this->countryContext->current()->marketSlug($application->locale).'/join/resume?'.http_build_query(['number' => $application->number, 'token' => $token]);

        $this->mail($application, 'partner_application.changes_requested', __('Your Nawy application needs changes'), [
            __('Please update your :type application.', ['type' => $application->type->label()]),
            __('What to change: :note', ['note' => $note]),
            __('Continue your application: :link', ['link' => $link]),
        ]);

        return $application;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function mail(PartnerApplication $application, string $kind, string $subject, array $lines): void
    {
        Notification::route('mail', $application->email)->notify(new RequestNotification($kind, $subject, $lines, $application->number));
    }
}
