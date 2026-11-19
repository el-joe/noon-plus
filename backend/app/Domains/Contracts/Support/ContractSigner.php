<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Contracts\Enums\ContractSignatureStatus;
use App\Domains\Contracts\Enums\ContractVersionStatus;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\StateMachine\StringStatusMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Records a signature with its evidence (SRS §13.6): the typed full name,
 * time, IP, user agent, version and locale, the consent method, a frozen
 * copy written to the private disk and the SHA-256 of exactly those bytes.
 * Earlier active signatures of the same signer, template and subject become
 * `superseded`. Only published versions can be signed.
 */
class ContractSigner
{
    public function __construct(private ContractRenderer $renderer, private CountryContext $countryContext) {}

    /**
     * @param  array<string, string|null>  $variables
     */
    public function sign(
        ContractVersion $version,
        SignerType $signerType,
        int $signerId,
        ?int $signerUserId,
        string $fullName,
        SignatureMethod $method = SignatureMethod::Click,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        string $locale = 'en',
        array $variables = [],
        ?string $purpose = null,
    ): ContractSignature {
        if ($version->status !== ContractVersionStatus::Published) {
            throw new TransitionRefused(__('Only a published contract version can be signed.'));
        }

        $fullName = trim($fullName);

        if ($fullName === '') {
            throw new TransitionRefused(__('Type your full name to sign.'));
        }

        $existing = $this->current($version, $signerType, $signerId, $subjectType, $subjectId);

        if ($existing !== null) {
            return $existing;
        }

        $template = $version->template;
        $signedAt = now();
        $country = $this->countryContext->current();
        $block = '<p>'.e(__('Signed by')).': '.e($fullName).'</p><p>'.e(__('Date')).': '.e($signedAt->toIso8601String()).'</p>'
            .'<p>'.e(__('Method')).': '.e($method->value).'</p><p>'.e(__('Version')).': '.$version->version.' ('.e($locale).')</p>'
            .'<p>IP: '.e((string) $ipAddress).'</p>';
        [$bytes, $extension] = $this->renderer->frozenCopy($version, $locale, $variables + ['signer_name' => $fullName, 'date' => $signedAt->toDateString(), 'country' => $country->name_en], $block);
        $path = sprintf('contracts/signatures/%s/%s/%s.%s', $country->code, $template->kind->value, Str::uuid(), $extension);
        Storage::disk(ContractRenderer::DISK)->put($path, $bytes);

        return DB::connection((new ContractSignature)->getConnectionName())->transaction(function () use ($version, $template, $signerType, $signerId, $signerUserId, $subjectType, $subjectId, $purpose, $fullName, $locale, $method, $ipAddress, $userAgent, $path, $bytes, $signedAt): ContractSignature {
            $previous = ContractSignature::query()
                ->where('contract_template_id', $template->id)
                ->where('signer_type', $signerType)
                ->where('signer_id', $signerId)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('status', ContractSignature::STATUS_ACTIVE)
                ->get();

            foreach ($previous as $signature) {
                $this->supersede($signature, $method === SignatureMethod::ContinuedUse ? ActorType::System : $this->actorFor($signerType), $signerUserId, 'Version '.$version->version.' signed');
            }

            return ContractSignature::query()->create([
                'contract_template_id' => $template->id,
                'contract_version_id' => $version->id,
                'version' => $version->version,
                'kind' => $template->kind,
                'signer_type' => $signerType,
                'signer_id' => $signerId,
                'signer_user_id' => $signerUserId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'purpose' => $purpose,
                'full_name' => mb_substr($fullName, 0, 160),
                'locale' => $locale,
                'method' => $method,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
                'frozen_path' => $path,
                'sha256' => hash('sha256', $bytes),
                'status' => ContractSignature::STATUS_ACTIVE,
                'open_call' => (bool) $template->open_call,
                'signed_at' => $signedAt,
            ]);
        });
    }

    public function current(ContractVersion $version, SignerType $signerType, int $signerId, ?string $subjectType = null, ?int $subjectId = null): ?ContractSignature
    {
        return ContractSignature::query()
            ->where('contract_version_id', $version->id)
            ->where('signer_type', $signerType)
            ->where('signer_id', $signerId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', ContractSignature::STATUS_ACTIVE)
            ->first();
    }

    public function hasCurrent(ContractVersion $version, SignerType $signerType, int $signerId, ?string $subjectType = null, ?int $subjectId = null): bool
    {
        return $this->current($version, $signerType, $signerId, $subjectType, $subjectId) !== null;
    }

    /**
     * The frozen copy still matches its recorded hash.
     */
    public function verify(ContractSignature $signature): bool
    {
        $bytes = Storage::disk(ContractRenderer::DISK)->get($signature->frozen_path);

        return $bytes !== null && hash_equals($signature->sha256, hash('sha256', $bytes));
    }

    /**
     * Active → superseded through the guarded move, with a history row (QC-053).
     */
    public function supersede(ContractSignature $signature, ActorType $actorType, ?int $actorUserId, ?string $reason = null): ContractSignature
    {
        return app(StringStatusMachine::class)->move($signature, ContractSignatureStatus::class, ContractSignatureStatus::Superseded, $actorType, $actorUserId, $reason, ['superseded_at' => now()]);
    }

    private function actorFor(SignerType $signerType): ActorType
    {
        return match ($signerType) {
            SignerType::Vendor => ActorType::Vendor,
            SignerType::Marketer => ActorType::Marketer,
            SignerType::Customer => ActorType::Customer,
            SignerType::Agency => ActorType::Agency,
            SignerType::Applicant => ActorType::Applicant,
        };
    }
}
