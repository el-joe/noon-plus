<?php

namespace App\Domains\Onboarding\Http\Controllers\Api;

use App\Domains\Contracts\Support\ContractRenderer;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Onboarding\Enums\ApplicationStatus;
use App\Domains\Onboarding\Enums\ApplicationType;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Onboarding\Support\ApplicationWizard;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Notifications\RequestNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Portal registration wizards API (MASTER_PLAN §5a, PORTAL_PAGES §2) for
 * every join section: open with the account step, save the other steps,
 * upload documents, read the agreement, submit, follow the status. Guests
 * reach their application with the `X-Application-Token` returned at
 * creation (or from a resume link mailed to the application's email).
 */
class PartnerApplicationController extends Controller
{
    public const TOKEN_HEADER = 'X-Application-Token';

    public function __construct(private ApplicationWizard $wizard) {}

    public function store(Request $request): JsonResponse
    {
        $type = ApplicationType::from($request->validate(['type' => ['required', Rule::enum(ApplicationType::class)]])['type']);
        $account = $request->validate($this->wizard->rules($type, 'account'));
        [$application, $token] = $this->wizard->start($type, $account, app()->getLocale());

        return ApiResponse::success($this->present($application), ['access_token' => $token, 'token_header' => self::TOKEN_HEADER], 201)
            ->header(self::TOKEN_HEADER, $token);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return ApiResponse::success($this->present($this->own($request, $number)));
    }

    public function saveStep(Request $request, string $number, string $step): JsonResponse
    {
        $application = $this->own($request, $number);

        if ($step === 'documents') {
            $missing = $this->wizard->missingDocuments($application);

            if ($missing->isNotEmpty()) {
                return ApiResponse::error('documents_missing', __('Upload the required documents first.'), 422, ['documents' => $missing->pluck('code')->all()]);
            }

            return ApiResponse::success($this->present($this->wizard->completeDocumentsStep($application)->refresh()));
        }

        $data = $request->validate($this->wizard->rules($application, $step));
        $this->wizard->saveStep($application, $step, $data, $request->file('logo'));

        return ApiResponse::success($this->present($application->refresh()));
    }

    public function uploadDocument(Request $request, string $number): JsonResponse
    {
        $application = $this->own($request, $number);
        $data = $request->validate([
            'document_type_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480'],
            'valid_until' => ['nullable', 'date', 'after:today'],
        ]);
        $document = $this->wizard->uploadDocument($application, (int) $data['document_type_id'], $request->file('file'), $data['valid_until'] ?? null);

        return ApiResponse::success(['id' => $document->id, 'status' => $document->status->value] + ['application' => $this->present($application->refresh())], status: 201);
    }

    /**
     * QA-002: the code mailed at the account step (or after an email change).
     */
    public function verifyEmail(Request $request, string $number): JsonResponse
    {
        $application = $this->own($request, $number);
        $code = $request->validate(['code' => ['required', 'string', 'max:12']])['code'];

        return ApiResponse::success($this->present($this->wizard->verifyEmail($application, $code)->refresh()));
    }

    public function resendEmailCode(Request $request, string $number): JsonResponse
    {
        $application = $this->own($request, $number);

        if ($application->email_verified_at === null && $application->status->isEditable()) {
            $this->wizard->sendEmailCode($application);
        }

        return ApiResponse::success(['sent' => $application->email_verified_at === null], status: 202);
    }

    public function removeDocument(Request $request, string $number, int $document): JsonResponse
    {
        $application = $this->own($request, $number);
        $this->wizard->removeDocument($application, $document);

        return ApiResponse::success($this->present($application->refresh()));
    }

    public function agreement(Request $request, string $number, ContractRenderer $renderer): JsonResponse
    {
        $application = $this->own($request, $number);
        $version = $this->wizard->agreement($application);

        return ApiResponse::success($version === null ? ['required' => false] : [
            'required' => true,
            'kind' => $application->type->contractKind()->value,
            'title' => $application->type->contractKind()->label(),
            'version_id' => $version->id,
            'version' => $version->version,
            'has_file' => $version->file_path_ar !== null || $version->file_path_en !== null,
            'text' => $version->body_en !== null || $version->body_ar !== null ? $renderer->text($version, $application->locale, $this->wizard->contractVariables($application)) : null,
        ]);
    }

    public function submit(Request $request, string $number): JsonResponse
    {
        $application = $this->own($request, $number);
        $data = $request->validate(['accept' => ['required', 'boolean'], 'full_name' => ['required', 'string', 'min:2', 'max:160']]);
        $this->wizard->submit($application, (bool) $data['accept'], $data['full_name'], $request->ip(), $request->userAgent());

        return ApiResponse::success($this->present($application->refresh()));
    }

    public function withdraw(Request $request, string $number): JsonResponse
    {
        return ApiResponse::success($this->present($this->wizard->withdraw($this->own($request, $number))->refresh()));
    }

    public function checkSlug(Request $request): JsonResponse
    {
        $slug = $request->validate(['slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/']])['slug'];

        return ApiResponse::success(['slug' => $slug, 'available' => $this->wizard->slugAvailable($slug)]);
    }

    /**
     * Mail a fresh link to an open application (the answer never says
     * whether one exists).
     */
    public function resumeLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'type' => ['required', Rule::enum(ApplicationType::class)]]);
        $application = PartnerApplication::query()->where('email', mb_strtolower($data['email']))->where('type', $data['type'])
            ->whereIn('status', [ApplicationStatus::Draft, ApplicationStatus::ChangesRequested])->latest('id')->first();

        if ($application !== null) {
            $token = $this->wizard->rotateToken($application);
            $link = $this->wizard->resumeLink($application, $token);
            Notification::route('mail', $application->email)->notify(new RequestNotification(
                'partner_application.resume', __('Continue your Nawy application'), [__('Continue your application: :link', ['link' => $link])], $application->number, $link, __('Continue your application'),
            ));
        }

        return ApiResponse::success(['sent' => true], status: 202);
    }

    private function own(Request $request, string $number): PartnerApplication
    {
        $application = PartnerApplication::query()->where('number', $number)->firstOrFail();
        abort_unless($application->matchesToken((string) $request->header(self::TOKEN_HEADER, '')), 404);

        return $application;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PartnerApplication $application): array
    {
        $uploads = $application->documents()->latest('id')->get()->groupBy('document_type_id');
        $missing = $this->wizard->missingDocuments($application)->pluck('id')->all();
        $agreement = $this->wizard->agreement($application);

        return [
            'number' => $application->number,
            'type' => $application->type->value,
            'status' => $application->status->value,
            'editable' => $application->status->isEditable(),
            'email_verified' => $application->email_verified_at !== null,
            'next_step' => $application->status->isEditable() ? $this->wizard->nextStep($application) : null,
            'steps' => array_map(fn (string $step): array => ['key' => $step, 'completed' => $application->hasCompleted($step)], $application->type->steps()),
            'account' => ['name' => $application->name, 'email' => $application->email, 'phone' => $application->phone] + $application->step('account'),
            'data' => collect($application->data ?? [])->except('account')->all(),
            'logo_url' => $application->logo_path === null ? null : Storage::disk('public')->url($application->logo_path),
            'documents' => $this->wizard->requirements($application)->map(fn (DocumentType $type): array => [
                'document_type_id' => $type->id,
                'code' => $type->code,
                'name' => $type->name(),
                'requirement' => $type->requirement->value,
                'has_expiry' => $type->has_expiry,
                'max_mb' => $type->max_mb,
                'missing' => in_array($type->id, $missing, true),
                'uploads' => $uploads->get($type->id, collect())->map(fn (Document $document): array => [
                    'id' => $document->id,
                    'status' => $document->status->value,
                    'original_name' => $document->original_name,
                    'rejection_reason' => $document->rejection_reason,
                    'valid_until' => $document->valid_until?->toDateString(),
                ])->values()->all(),
            ])->values()->all(),
            'agreement' => ['required' => $agreement !== null, 'kind' => $application->type->contractKind()->value, 'version_id' => $agreement?->id],
            'requested_changes' => $application->status === ApplicationStatus::ChangesRequested ? $application->requested_changes : null,
            'decision_reason' => $application->status === ApplicationStatus::Rejected ? $application->decision_reason : null,
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'decided_at' => $application->decided_at?->toIso8601String(),
        ];
    }
}
