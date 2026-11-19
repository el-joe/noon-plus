<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\ContractVersionStatus;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Settings\SettingsService;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\PartnerEmails;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Template authoring and lifecycle (SRS §13.2–13.4, lifecycle catalogue
 * §8.1). Drafts are numbered v1, v2…; a published version is never edited.
 * Publishing (click-wrap) retires the previous version and supersedes the
 * signatures of older versions in the template's countries, so signers
 * must re-sign. Notice mode (Seller Agreement only) schedules the version
 * for its effective date; `contracts:apply-effective` (hourly) publishes it
 * and records continued use as consent for everyone who had signed the
 * previous version.
 */
class ContractPublisher
{
    public function __construct(
        private CountryContext $countryContext,
        private ContractSigner $signer,
        private SettingsService $settings,
    ) {}

    /**
     * @param  array{body_en?: string|null, body_ar?: string|null, change_summary?: string|null}  $content
     */
    public function draft(ContractTemplate $template, array $content, ?UploadedFile $fileAr = null, ?UploadedFile $fileEn = null): ContractVersion
    {
        $draft = $template->versions()->where('status', ContractVersionStatus::Draft)->first();
        $attributes = [
            'body_en' => $content['body_en'] ?? null,
            'body_ar' => $content['body_ar'] ?? null,
            'change_summary' => $content['change_summary'] ?? null,
        ];

        if ($template->mode === 'file') {
            $attributes = ['change_summary' => $attributes['change_summary']];

            foreach (['ar' => $fileAr, 'en' => $fileEn] as $locale => $file) {
                if ($file !== null) {
                    $attributes["file_path_{$locale}"] = $file->storeAs("contracts/templates/{$template->id}", Str::uuid().'.'.($file->extension() ?: 'bin'), ContractRenderer::DISK);
                    $attributes['file_mime'] = $file->getMimeType();
                }
            }
        }

        if ($draft !== null) {
            $draft->fill($attributes)->save();

            return $draft;
        }

        return $template->versions()->create($attributes + [
            'version' => (int) $template->versions()->max('version') + 1,
            'status' => ContractVersionStatus::Draft,
        ]);
    }

    /**
     * Publish now (click-wrap) or schedule (notice with a future effective date).
     */
    public function publish(ContractVersion $version, ?int $adminUserId = null, ?CarbonInterface $effectiveAt = null): ContractVersion
    {
        $template = $version->template;

        if ($version->status !== ContractVersionStatus::Draft) {
            throw new TransitionRefused(__('Only a draft can be published; edit the contract to create a new draft.'));
        }

        $this->assertComplete($version, $template);
        $signers = $this->signersToNotify($template, $version);

        if ($template->consent_mode === 'notice' && $template->kind->allowsNotice() && $template->publishedVersion()->exists()) {
            $effectiveAt ??= now()->addDays((int) $this->settings->get('contracts.notice_default_days', 14));

            if ($effectiveAt->isFuture()) {
                $version->forceFill(['status' => ContractVersionStatus::Scheduled, 'effective_at' => $effectiveAt, 'published_by' => $adminUserId])->save();
                $this->mailSigners($template, $version, $signers, $effectiveAt);

                return $version;
            }
        }

        $published = $this->makeCurrent($version, $adminUserId, continuedUse: false);
        $this->mailSigners($template, $version, $signers, now());

        return $published;
    }

    /**
     * Partners with an active signature of an earlier version, per country
     * (read before publishing supersedes them): store / agency / marketer /
     * shipping-company owners — customers and applicants are left out.
     *
     * @return array<int, array{country: Country, type: string, id: int}>
     */
    private function signersToNotify(ContractTemplate $template, ContractVersion $version): array
    {
        $signers = [];

        foreach ($this->countriesOf($template) as $country) {
            $this->countryContext->runAs($country, function () use ($template, $version, $country, &$signers): void {
                ContractSignature::query()
                    ->where('contract_template_id', $template->id)
                    ->where('contract_version_id', '!=', $version->id)
                    ->where('status', ContractSignature::STATUS_ACTIVE)
                    ->whereIn('signer_type', [SignerType::Vendor, SignerType::Marketer, SignerType::Agency])
                    ->get(['signer_type', 'signer_id'])
                    ->each(function (ContractSignature $signature) use ($template, $country, &$signers): void {
                        $type = match ($signature->signer_type) {
                            SignerType::Marketer => 'marketer',
                            SignerType::Agency => $template->kind === ContractKind::ShippingCompany ? 'carrier' : 'vendor',
                            default => 'vendor',
                        };
                        $signers[$country->id.':'.$type.':'.$signature->signer_id] = ['country' => $country, 'type' => $type, 'id' => (int) $signature->signer_id];
                    });
            });
        }

        return array_values($signers);
    }

    /**
     * `contract.update_to_sign` to every signer of the previous version.
     *
     * @param  array<int, array{country: Country, type: string, id: int}>  $signers
     */
    private function mailSigners(ContractTemplate $template, ContractVersion $version, array $signers, CarbonInterface $effectiveAt): void
    {
        foreach ($signers as $signer) {
            $this->countryContext->runAs($signer['country'], fn () => app(PartnerEmails::class)->toOwner($signer['type'], $signer['id'], 'contract.update_to_sign', [
                'contract' => (string) $template->name,
                'version' => 'v'.$version->version,
                'effective' => EmailValue::date($effectiveAt),
            ], match ($signer['type']) {
                'marketer' => '/contract',
                'carrier' => '/',
                default => '/contracts',
            }, ['entity' => $version]));
        }
    }

    /**
     * Hourly: scheduled notice versions whose effective date has come.
     */
    public function applyEffective(): int
    {
        $applied = 0;

        ContractVersion::query()->where('status', ContractVersionStatus::Scheduled)->where('effective_at', '<=', now())->orderBy('id')
            ->each(function (ContractVersion $version) use (&$applied): void {
                $this->makeCurrent($version, $version->published_by, continuedUse: true);
                $applied++;
            });

        return $applied;
    }

    private function makeCurrent(ContractVersion $version, ?int $adminUserId, bool $continuedUse): ContractVersion
    {
        $template = $version->template;

        DB::transaction(function () use ($version, $template, $adminUserId): void {
            /*
            | QC-053: versions are central rows audited by the activity log;
            | each retirement is a checked move saved on its own.
            */
            foreach ($template->versions()->where('status', ContractVersionStatus::Published)->where('id', '!=', $version->id)->get() as $previous) {
                if (in_array(ContractVersionStatus::Retired, $previous->status->allowedNext(), true)) {
                    $previous->forceFill(['status' => ContractVersionStatus::Retired, 'retired_at' => now()])->save();
                }
            }
            $version->forceFill(['status' => ContractVersionStatus::Published, 'published_at' => now(), 'published_by' => $adminUserId])->save();
        });

        foreach ($this->countriesOf($template) as $country) {
            $this->countryContext->runAs($country, function () use ($version, $template, $continuedUse, $adminUserId): void {
                $stale = ContractSignature::query()
                    ->where('contract_template_id', $template->id)
                    ->where('contract_version_id', '!=', $version->id)
                    ->where('status', ContractSignature::STATUS_ACTIVE)
                    ->get();

                foreach ($stale as $signature) {
                    if ($continuedUse) {
                        $this->signer->sign($version->refresh(), $signature->signer_type, $signature->signer_id, $signature->signer_user_id, $signature->full_name, SignatureMethod::ContinuedUse, subjectType: $signature->subject_type, subjectId: $signature->subject_id, locale: $signature->locale);
                    } else {
                        $this->signer->supersede($signature, ActorType::Admin, $adminUserId, 'Version '.$version->version.' published');
                    }
                }
            });
        }

        return $version->refresh();
    }

    /**
     * @return iterable<int, Country>
     */
    private function countriesOf(ContractTemplate $template): iterable
    {
        return $template->country_id === null ? Country::query()->active()->get() : Country::query()->whereKey($template->country_id)->get();
    }

    private function assertComplete(ContractVersion $version, ContractTemplate $template): void
    {
        $requireArabic = (bool) $this->settings->get('contracts.require_arabic', true);

        if ($template->mode === 'file') {
            if ($version->file_path_ar === null && ($requireArabic || $version->file_path_en === null)) {
                throw new TransitionRefused(__('Upload the Arabic contract file before publishing.'));
            }

            return;
        }

        if (blank($version->body_ar) && ($requireArabic || blank($version->body_en))) {
            throw new TransitionRefused(__('Write the Arabic contract text before publishing.'));
        }
    }

    /**
     * Who signs a kind (for listings of signatures).
     */
    public static function signerTypeFor(string $kind): SignerType
    {
        return match ($kind) {
            'marketer_onboarding', 'broker', 'exclusive' => SignerType::Marketer,
            'customer_purchase', 'trip' => SignerType::Customer,
            'travel_agency', 'shipping_company' => SignerType::Agency,
            default => SignerType::Vendor,
        };
    }
}
