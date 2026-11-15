<?php

namespace App\Domains\Onboarding\Http\Controllers\Admin;

use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Onboarding\Enums\ApplicationStatus;
use App\Domains\Onboarding\Enums\ApplicationType;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Onboarding\Support\ApplicationReview;
use App\Domains\Onboarding\Support\ApplicationWizard;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Users & vendors → Applications (SRS §4.1.5, Figma approvals
 * queue 96:2483): the queue per join section, the application with its
 * wizard data, documents, agreement signature and history (times in Oman
 * time), and the decisions.
 */
class ApplicationReviewController extends Controller
{
    public function __construct(private ApplicationReview $review, private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(ApplicationType::class)],
            'status' => ['nullable', Rule::in(array_merge(['review'], array_map(fn (ApplicationStatus $status): string => $status->value, ApplicationStatus::cases())))],
            'mine' => ['nullable', 'boolean'],
        ]);
        $status = $filters['status'] ?? 'review';

        $applications = PartnerApplication::query()
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($status === 'review', fn ($query) => $query->whereIn('status', ApplicationStatus::inReview()), fn ($query) => $query->where('status', $status))
            ->when($filters['mine'] ?? false, fn ($query) => $query->where('assigned_to_user_id', $request->user()->id))
            ->withCount(['documents', 'documents as approved_documents_count' => fn ($query) => $query->where('status', DocumentStatus::Approved)])
            ->orderByRaw('submitted_at is null')->orderBy('submitted_at')->latest('id')
            ->paginate(25)->withQueryString();

        return view('panels.admin.applications.index', [
            'applications' => $applications,
            'counts' => PartnerApplication::query()->whereIn('status', ApplicationStatus::inReview())->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type'),
            'reviewers' => User::query()->whereIn('id', $applications->pluck('assigned_to_user_id')->filter()->unique())->pluck('name', 'id'),
            'filters' => $filters + ['status' => $status],
            'types' => ApplicationType::cases(),
            'statuses' => ApplicationStatus::cases(),
        ]);
    }

    public function show(int $application, ApplicationWizard $wizard): View
    {
        $application = PartnerApplication::query()->findOrFail($application);
        $history = StateTransition::query()->where('subject_type', $application->transitionSubject())->where('subject_id', $application->id)->orderBy('id')->get();

        return view('panels.admin.applications.show', [
            'application' => $application,
            'documents' => $this->documentsOf($application),
            'types' => DocumentType::query()->where('country_id', $this->countryContext->current()->id)->get()->keyBy('id'),
            'missing' => $application->status === ApplicationStatus::Approved ? collect() : $wizard->missingDocuments($application),
            'signature' => $this->signatureOf($application),
            'agreementPublished' => $wizard->agreement($application) !== null,
            'history' => $history,
            'actors' => User::query()->whereIn('id', $history->pluck('actor_user_id')->push($application->assigned_to_user_id)->filter()->unique())->pluck('name', 'id'),
        ]);
    }

    public function assign(Request $request, int $application): RedirectResponse
    {
        $this->review->assign(PartnerApplication::query()->findOrFail($application), $request->user()->id);

        return back()->with('status', __('Assigned to you.'));
    }

    public function decide(Request $request, int $application): RedirectResponse
    {
        $application = PartnerApplication::query()->findOrFail($application);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['start', 'approve', 'reject', 'changes'])],
            'reason' => ['nullable', 'string', 'max:1000'],
            'steps' => ['nullable', 'array'],
            'steps.*' => ['string', Rule::in($application->type->dataSteps())],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer'],
        ]);
        $adminId = $request->user()->id;

        match ($data['decision']) {
            'start' => $this->review->startReview($application, $adminId),
            'approve' => $this->review->approve($application, $adminId, $data['reason'] ?? null),
            'reject' => $this->review->reject($application, $adminId, (string) ($data['reason'] ?? '')),
            'changes' => $this->review->requestChanges($application, $adminId, (string) ($data['reason'] ?? ''), $data['steps'] ?? [], $data['document_ids'] ?? []),
        };

        return redirect()->route('admin.applications.show', $application->id)->with('status', match ($data['decision']) {
            'start' => __('Review started.'),
            'approve' => __('Approved — the account was created and the welcome email sent.'),
            'reject' => __('Rejected — the applicant was told why.'),
            'changes' => __('Sent back to the applicant for changes.'),
        });
    }

    public function reviewDocument(Request $request, int $application, int $document, Documents $documents): RedirectResponse
    {
        $application = PartnerApplication::query()->findOrFail($application);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['nullable', 'string', 'max:255']]);
        $document = $this->documentsOf($application)->firstWhere('id', $document) ?? abort(404);

        $data['decision'] === 'approve'
            ? $documents->approve($document, $request->user()->id)
            : $documents->reject($document, $request->user()->id, (string) ($data['reason'] ?? ''));

        return back()->with('status', __('Document updated.'));
    }

    public function download(int $application, int $document): StreamedResponse
    {
        $application = PartnerApplication::query()->findOrFail($application);
        $document = $this->documentsOf($application)->firstWhere('id', $document) ?? abort(404);

        return Storage::disk(Documents::DISK)->download($document->file_path, $document->original_name);
    }

    /**
     * The application's documents — still under the application, or moved
     * to the tenant once approved.
     *
     * @return Collection<int, Document>
     */
    private function documentsOf(PartnerApplication $application): Collection
    {
        if ($application->tenant_id !== null) {
            $ownerType = $application->tenant_type === 'marketer' ? 'marketer' : ($application->tenant_type === 'carrier' ? 'carrier' : 'vendor');

            return Document::query()->where('owner_type', $ownerType)->where('owner_id', $application->tenant_id)->orderBy('id')->get();
        }

        return $application->documents()->orderBy('id')->get();
    }

    private function signatureOf(PartnerApplication $application): ?ContractSignature
    {
        $query = ContractSignature::query()->where('kind', $application->type->contractKind());

        return $application->tenant_id === null
            ? $query->where('signer_type', SignerType::Applicant)->where('signer_id', $application->id)->latest('id')->first()
            : $query->where('signer_id', $application->tenant_id)->where('signer_type', '!=', SignerType::Applicant)->latest('id')->first();
    }
}
