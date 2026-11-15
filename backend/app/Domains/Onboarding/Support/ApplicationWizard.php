<?php

namespace App\Domains\Onboarding\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Contracts\Support\ContractResolver;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Onboarding\Enums\ApplicationStatus;
use App\Domains\Onboarding\Enums\ApplicationType;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Onboarding\Notifications\ApplicationEmailCodeNotification;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Http\StorefrontLinks;
use App\Shared\Notifications\RequestNotification;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Validation\CountryPhone;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The portal registration wizards (MASTER_PLAN §5a, PORTAL_PAGES §2): the
 * account step opens a draft, returns its access token and mails a code
 * that proves the email (QA-002, required before submission); every other
 * data step is saved (and can be saved again) while the application is a
 * draft or waiting for changes; documents per country × business type are
 * uploaded to the Documents domain under the application; submission
 * checks every step and the mandatory documents and records the click-wrap
 * signature of the section's agreement (Contracts domain) when the country
 * has one published.
 */
class ApplicationWizard
{
    public function __construct(
        private CountryContext $countryContext,
        private StateMachine $machine,
        private Documents $documents,
        private ContractResolver $contracts,
        private ContractSigner $signer,
        private JoinOptions $joinOptions,
    ) {}

    /**
     * Step 1 (account): opens the draft.
     *
     * @param  array<string, mixed>  $account  validated {@see self::rules()} for `account`
     * @return array{0: PartnerApplication, 1: string} the application and its access token
     */
    public function start(ApplicationType $type, array $account, string $locale): array
    {
        if (! $this->joinOptions->enabled($type->joinSection(), $this->countryContext->current())) {
            throw new TransitionRefused(__('Applications for this section are closed in this country.'));
        }

        $email = Str::lower($account['email']);
        $this->guardEmail($type, $email);
        $token = Str::random(48);

        $application = PartnerApplication::query()->create([
            'number' => $this->newNumber(),
            'type' => $type,
            'status' => ApplicationStatus::Draft,
            'token_hash' => hash('sha256', $token),
            'email' => $email,
            'name' => $account['name'],
            'phone' => CountryPhone::normalize($account['phone']),
            'password_hash' => Hash::make($account['password']),
            'locale' => in_array($locale, ['en', 'ar'], true) ? $locale : 'en',
            'data' => ['account' => $this->accountData($account)],
            'completed_steps' => ['account'],
        ]);
        $this->machine->created($application, ActorType::Applicant);
        $this->sendEmailCode($application);

        return [$application, $token];
    }

    /**
     * Validation rules of a step.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(PartnerApplication|ApplicationType $subject, string $step): array
    {
        $type = $subject instanceof PartnerApplication ? $subject->type : $subject;
        $application = $subject instanceof PartnerApplication ? $subject : null;

        return match ($step) {
            'account' => [
                'name' => ['required', 'string', 'min:2', 'max:120'],
                'email' => ['required', 'email', 'max:255'],
                'phone' => ['required', 'string', 'max:24', new CountryPhone],
                'password' => [$application === null ? 'required' : 'nullable', 'string', 'min:8', 'max:100', 'confirmed'],
                'gender' => ['nullable', Rule::in(['male', 'female'])],
            ],
            'store' => [
                'store_name' => ['required', 'string', 'min:2', 'max:120'],
                'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'business_type' => ['required', Rule::in($type === ApplicationType::Classified ? ['individual', 'business'] : ['llc', 'sole_proprietorship', 'partnership', 'corporation'])],
                'legal_name' => ['required_unless:business_type,individual', 'nullable', 'string', 'max:160'],
                'cr_number' => ['required_unless:business_type,individual', 'nullable', 'string', 'max:64'],
                'licence_expiry' => ['nullable', 'date', 'after:today'],
                'tax_id' => ['nullable', 'string', 'max:32'],
                'description' => ['nullable', 'string', 'max:1000'],
                'main_category_id' => ['nullable', 'integer', $this->existingCategory()],
                'fulfilment' => [$type === ApplicationType::Vendor ? 'required' : 'nullable', Rule::in(['fbm', 'fbn', 'both'])],
            ],
            'address' => [
                'city' => ['required', 'string', 'max:80'],
                'area' => ['nullable', 'string', 'max:80'],
                'street' => ['required', 'string', 'max:160'],
                'building' => ['nullable', 'string', 'max:60'],
                'postal_code' => ['nullable', 'string', 'max:16'],
                'landmark' => ['nullable', 'string', 'max:160'],
            ],
            'agency' => [
                'agency_name' => ['required', 'string', 'min:2', 'max:120'],
                'licence_number' => ['required', 'string', 'max:64'],
                'city' => ['nullable', 'string', 'max:80'],
                'description' => ['nullable', 'string', 'max:1000'],
                'logo' => [$application?->logo_path === null ? 'required' : 'nullable', 'image', 'max:2048'],
            ],
            'profile' => $type === ApplicationType::Broker ? [
                'display_name' => ['required', 'string', 'min:2', 'max:120'],
                'licence_number' => ['required', 'string', 'max:64'],
                'licence_expiry' => ['nullable', 'date', 'after:today'],
                'company_name' => ['nullable', 'string', 'max:160'],
                'areas' => ['nullable', 'array', 'max:20'],
                'areas.*' => ['string', 'max:80'],
                'whatsapp_phone' => ['required', 'string', 'max:24', new CountryPhone],
                'bio' => ['nullable', 'string', 'max:160'],
            ] : [
                'job_types' => ['required', 'array', 'min:1'],
                'job_types.*' => ['string', Rule::in(['influencer', 'affiliate'])],
                'display_name' => ['required', 'string', 'min:2', 'max:120'],
                'niche' => ['nullable', 'string', 'max:80'],
                'bio' => ['nullable', 'string', 'max:160'],
                'whatsapp_phone' => ['required', 'string', 'max:24', new CountryPhone],
                'followers' => ['nullable', 'integer', 'min:0'],
                'languages' => ['nullable', 'array', 'max:6'],
                'languages.*' => ['string', 'max:24'],
                'socials' => ['nullable', 'array', 'max:6'],
                'socials.*.network' => ['required', Rule::in(['instagram', 'tiktok', 'youtube', 'snapchat', 'x', 'facebook'])],
                'socials.*.handle' => ['required', 'string', 'max:120'],
            ],
            'company' => [
                'legal_name' => ['required', 'string', 'min:2', 'max:160'],
                'licence_number' => ['required', 'string', 'max:64'],
                'coverage' => ['nullable', 'array', 'max:40'],
                'coverage.*' => ['string', 'max:80'],
                'fleet_size' => ['nullable', 'integer', 'min:0', 'max:100000'],
                'services' => ['nullable', 'array'],
                'services.*' => [Rule::in(['same_day', 'next_day', 'standard', 'cod', 'returns', 'bulk'])],
                'api_integration' => ['nullable', 'string', 'max:160'],
            ],
            default => throw new TransitionRefused(__('This step does not take any data.')),
        };
    }

    /**
     * Save a data step (again) and mark it complete.
     *
     * @param  array<string, mixed>  $data  validated with {@see self::rules()}
     */
    public function saveStep(PartnerApplication $application, string $step, array $data, ?UploadedFile $logo = null): PartnerApplication
    {
        $this->guardEditable($application);

        if (! in_array($step, $application->type->dataSteps(), true)) {
            throw new TransitionRefused(__('This application has no :step step.', ['step' => $step]));
        }

        $attributes = [];

        if ($step === 'account') {
            $email = Str::lower($data['email']);

            $emailChanged = $email !== $application->email;

            if ($emailChanged) {
                $this->guardEmail($application->type, $email, $application->id);
            }

            $attributes = ['email' => $email, 'name' => $data['name'], 'phone' => CountryPhone::normalize($data['phone'])]
                + ($emailChanged ? ['email_verified_at' => null] : []);

            if (($data['password'] ?? null) !== null) {
                $attributes['password_hash'] = Hash::make($data['password']);
            }

            $data = $this->accountData($data);
        }

        if ($step === 'store') {
            $this->guardSlug($data['slug'], $application->id);
            $attributes['store_slug'] = $data['slug'];
        }

        if ($step === 'agency') {
            unset($data['logo']);

            if ($logo !== null) {
                if ($application->logo_path !== null) {
                    Storage::disk('public')->delete($application->logo_path);
                }

                $attributes['logo_path'] = $logo->storeAs('applications/'.$this->countryContext->current()->code.'/'.$application->number, 'logo-'.Str::random(8).'.'.$logo->extension(), 'public');
            }
        }

        foreach (['whatsapp_phone', 'contact_phone'] as $phoneField) {
            if (isset($data[$phoneField])) {
                $data[$phoneField] = CountryPhone::normalize($data[$phoneField]);
            }
        }

        $application->forceFill($attributes + [
            'data' => array_merge($application->data ?? [], [$step => $data]),
            'completed_steps' => array_values(array_unique(array_merge($application->completed_steps ?? [], [$step]))),
        ])->save();

        if ($step === 'account' && $application->email_verified_at === null && ($emailChanged ?? false)) {
            $this->sendEmailCode($application);
        }

        return $application;
    }

    /**
     * Document types that apply to this application (country × type).
     *
     * @return Collection<int, DocumentType>
     */
    public function requirements(PartnerApplication $application): Collection
    {
        return $this->documents->requirements($application->type->documentOwner());
    }

    /**
     * Mandatory types without a pending or approved upload.
     *
     * @return Collection<int, DocumentType>
     */
    public function missingDocuments(PartnerApplication $application): Collection
    {
        return $this->documents->missingMandatory($application->type->documentOwner(), PartnerApplication::DOCUMENT_OWNER, $application->id);
    }

    public function uploadDocument(PartnerApplication $application, int $documentTypeId, UploadedFile $file, ?string $validUntil = null): Document
    {
        $this->guardEditable($application);
        $type = $this->requirements($application)->firstWhere('id', $documentTypeId)
            ?? throw new TransitionRefused(__('This document is not asked for this application.'));

        if ($file->getSize() > $type->max_mb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => __('The file may not be larger than :mb MB.', ['mb' => $type->max_mb])]);
        }

        if ($type->has_expiry && $validUntil === null) {
            throw ValidationException::withMessages(['valid_until' => __('Enter the expiry date of this document.')]);
        }

        Document::query()->where('owner_type', PartnerApplication::DOCUMENT_OWNER)->where('owner_id', $application->id)
            ->where('document_type_id', $type->id)->where('status', DocumentStatus::Pending)
            ->get()->each(fn (Document $previous) => $this->deleteDocument($previous));

        $document = $this->documents->upload($type, PartnerApplication::DOCUMENT_OWNER, $application->id, $file, null, null, $validUntil);
        $this->refreshDocumentsStep($application);

        return $document;
    }

    /**
     * An applicant removes a pending upload (reviewed files stay as evidence).
     */
    public function removeDocument(PartnerApplication $application, int $documentId): void
    {
        $this->guardEditable($application);
        $document = $application->documents()->findOrFail($documentId);

        if ($document->status !== DocumentStatus::Pending) {
            throw new TransitionRefused(__('Reviewed documents cannot be removed.'));
        }

        $this->deleteDocument($document);
        $this->refreshDocumentsStep($application);
    }

    /**
     * The agreement signed at submission, when the country has one published.
     */
    public function agreement(PartnerApplication $application): ?ContractVersion
    {
        return $this->contracts->current($application->type->contractKind(), $this->countryContext->current());
    }

    /**
     * Last step: every data step and mandatory document present, the
     * agreement accepted with the typed full name (click-wrap with
     * evidence), then submitted for review.
     */
    public function submit(PartnerApplication $application, bool $accepted, string $fullName, ?string $ipAddress = null, ?string $userAgent = null): PartnerApplication
    {
        $this->guardEditable($application);

        if (! $this->joinOptions->enabled($application->type->joinSection(), $this->countryContext->current()) && $application->status === ApplicationStatus::Draft) {
            throw new TransitionRefused(__('Applications for this section are closed in this country.'));
        }

        if ($application->email_verified_at === null) {
            throw ValidationException::withMessages(['email_code' => __('Confirm your email with the code we sent before you submit.')]);
        }

        $missingSteps = array_values(array_filter($application->type->dataSteps(), fn (string $step): bool => ! $application->hasCompleted($step)));

        if ($missingSteps !== []) {
            throw new TransitionRefused(__('Complete these steps first: :steps.', ['steps' => implode(', ', $missingSteps)]));
        }

        $missing = $this->missingDocuments($application);

        if ($missing->isNotEmpty()) {
            throw new TransitionRefused(__('Upload the required documents first: :documents.', ['documents' => $missing->map(fn (DocumentType $type): string => $type->name())->implode(', ')]));
        }

        if (! $accepted) {
            throw ValidationException::withMessages(['accept' => __('Accept the agreement to submit your application.')]);
        }

        $agreement = $this->agreement($application);

        if ($agreement !== null) {
            $this->signer->sign(
                $agreement, SignerType::Applicant, $application->id, null, $fullName, SignatureMethod::Registration, $ipAddress, $userAgent,
                locale: $application->locale, variables: $this->contractVariables($application),
            );
        }

        $first = $application->status === ApplicationStatus::Draft;
        $this->machine->move($application, ApplicationStatus::Submitted, ActorType::Applicant, null, $first ? null : 'Resubmitted with changes', [
            'submitted_at' => now(),
            'completed_steps' => $application->type->steps(),
            'requested_changes' => null,
        ], ['agreement_version_id' => $agreement?->id, 'signed_name' => mb_substr(trim($fullName), 0, 160)]);

        Notification::route('mail', $application->email)->notify(new RequestNotification(
            'partner_application.submitted',
            __('We received your application'),
            [__('Thank you, :name. Your :type application is now waiting for review.', ['name' => $application->name, 'type' => $application->type->label()]), __('We will email you when there is a decision.')],
            $application->number,
        ));

        return $application;
    }

    /**
     * Mail a fresh 6-digit code to the application's email (QA-002); the
     * previous code stops working.
     */
    public function sendEmailCode(PartnerApplication $application): void
    {
        $code = (string) random_int(100000, 999999);
        $minutes = (int) config('nawy.onboarding.email_code_minutes', 30);
        $application->forceFill([
            'email_code_hash' => hash('sha256', $application->number.'|'.$code),
            'email_code_expires_at' => now()->addMinutes($minutes),
            'email_code_attempts' => 0,
        ])->save();

        Notification::route('mail', $application->email)->notify(new ApplicationEmailCodeNotification(
            $code, $application->number, $minutes, User::query()->where('email', $application->email)->exists(),
        ));
    }

    /**
     * The applicant proves the email (QA-002). Five wrong tries or an
     * expired code need a new code. When the email already belongs to an
     * account, this proof is the owner's consent to add the workspace to
     * it; the wizard password is then dropped and never replaces theirs.
     */
    public function verifyEmail(PartnerApplication $application, string $code): PartnerApplication
    {
        if ($application->email_verified_at !== null) {
            return $application;
        }

        if ($application->email_code_hash === null || $application->email_code_expires_at === null || now()->gt($application->email_code_expires_at) || $application->email_code_attempts >= 5) {
            throw ValidationException::withMessages(['code' => __('This code has expired. Ask for a new one.')]);
        }

        if (! hash_equals($application->email_code_hash, hash('sha256', $application->number.'|'.trim($code)))) {
            $application->increment('email_code_attempts');

            throw ValidationException::withMessages(['code' => __('That code is not right.')]);
        }

        $existing = User::query()->where('email', $application->email)->exists();
        $application->forceFill([
            'email_verified_at' => now(),
            'email_code_hash' => null,
            'email_code_expires_at' => null,
            'email_code_attempts' => 0,
        ] + ($existing ? ['password_hash' => null] : []))->save();

        return $application;
    }

    public function withdraw(PartnerApplication $application): PartnerApplication
    {
        return $this->machine->move($application, ApplicationStatus::Withdrawn, ActorType::Applicant);
    }

    /**
     * A new access token (the old one stops working), e.g. for a resume link.
     */
    public function rotateToken(PartnerApplication $application): string
    {
        $token = Str::random(48);
        $application->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function slugAvailable(string $slug, ?int $ignoreApplicationId = null): bool
    {
        return ! Vendor::query()->where('slug', $slug)->exists()
            && ! PartnerApplication::query()->where('store_slug', $slug)->whereIn('status', ApplicationStatus::open())
                ->when($ignoreApplicationId, fn ($query, int $id) => $query->whereKeyNot($id))->exists();
    }

    /**
     * The step the applicant should open next.
     */
    public function nextStep(PartnerApplication $application): ?string
    {
        foreach ($application->type->steps() as $step) {
            if (! $application->hasCompleted($step)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @return array<string, string|null>
     */
    public function contractVariables(PartnerApplication $application): array
    {
        return [
            'store_name' => $application->step('store')['store_name'] ?? $application->step('agency')['agency_name'] ?? $application->step('company')['legal_name'] ?? $application->step('profile')['display_name'] ?? $application->name,
            'cr_number' => $application->step('store')['cr_number'] ?? $application->step('agency')['licence_number'] ?? $application->step('company')['licence_number'] ?? null,
            'marketer_name' => $application->step('profile')['display_name'] ?? $application->name,
            'address' => collect($application->step('address'))->only(['street', 'building', 'area', 'city'])->filter()->implode(', ') ?: null,
        ];
    }

    private function guardEditable(PartnerApplication $application): void
    {
        if (! $application->status->isEditable()) {
            throw new TransitionRefused(__('This application can no longer be changed.'));
        }
    }

    /**
     * One open application per email and type; existing partners of the
     * type in this country cannot apply again.
     */
    private function guardEmail(ApplicationType $type, string $email, ?int $ignoreApplicationId = null): void
    {
        $open = PartnerApplication::query()->where('email', $email)->where('type', $type)
            ->whereIn('status', ApplicationStatus::open())
            ->when($ignoreApplicationId, fn ($query, int $id) => $query->whereKeyNot($id))->exists();

        if ($open) {
            throw ValidationException::withMessages(['email' => __('There is already an open application with this email. Use the link we emailed you to continue it.')]);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        $country = $this->countryContext->current();
        $isPartner = in_array($type, [ApplicationType::Marketer, ApplicationType::Broker], true)
            ? MarketerProfile::query()->where('user_id', $user->id)->exists()
            : $user->memberships()->where('role', $type->membershipRole())->where('country_id', $country->id)->where('status', 'active')->exists();

        if ($isPartner) {
            throw ValidationException::withMessages(['email' => __('This email already has a :type account in this country. Sign in instead.', ['type' => $type->label()])]);
        }

        if ($type === ApplicationType::Carrier && $user->hasMembership(MembershipRole::CarrierSupervisor, $country)) {
            throw ValidationException::withMessages(['email' => __('This email already belongs to a shipping company in this country.')]);
        }
    }

    private function guardSlug(string $slug, int $applicationId): void
    {
        if (! $this->slugAvailable($slug, $applicationId)) {
            throw ValidationException::withMessages(['slug' => __('This store address is taken. Try another one.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    private function accountData(array $account): array
    {
        return array_filter(['gender' => $account['gender'] ?? null], fn ($value): bool => $value !== null);
    }

    /**
     * Saving the documents step (G-W28): marks it complete once every
     * mandatory document is uploaded (also when none are asked for).
     */
    public function completeDocumentsStep(PartnerApplication $application): PartnerApplication
    {
        $this->guardEditable($application);
        $this->refreshDocumentsStep($application);

        return $application;
    }

    /**
     * The website's resume page (G-W28): `{storefront}/{market}/join/resume?number=&token=`
     * in the application's language.
     */
    public function resumeLink(PartnerApplication $application, string $token): string
    {
        return app(StorefrontLinks::class)->to('join/resume', ['number' => $application->number, 'token' => $token], $application->locale);
    }

    private function refreshDocumentsStep(PartnerApplication $application): void
    {
        if (! in_array('documents', $application->type->steps(), true)) {
            return;
        }

        $steps = array_values(array_diff($application->completed_steps ?? [], ['documents']));

        if ($this->missingDocuments($application)->isEmpty()) {
            $steps[] = 'documents';
        }

        $application->forceFill(['completed_steps' => $steps])->save();
    }

    private function deleteDocument(Document $document): void
    {
        Storage::disk(Documents::DISK)->delete($document->file_path);
        $document->delete();
    }

    private function existingCategory(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! Category::query()->whereKey($value)->exists()) {
                $fail(__('Choose a category from the list.'));
            }
        };
    }

    private function newNumber(): string
    {
        do {
            $number = 'APP-'.Str::upper($this->countryContext->current()->code).'-'.Str::upper(Str::random(6));
        } while (PartnerApplication::query()->where('number', $number)->exists());

        return $number;
    }
}
