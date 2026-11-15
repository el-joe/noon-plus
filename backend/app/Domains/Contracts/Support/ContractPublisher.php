<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Contracts\Enums\ContractVersionStatus;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Settings\SettingsService;
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
                    $attributes["file_path_{$locale}"] = $file->storeAs("contracts/templates/{$template->id}", Str::uuid().'.'.$file->getClientOriginalExtension(), ContractRenderer::DISK);
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

        if ($template->consent_mode === 'notice' && $template->kind->allowsNotice() && $template->publishedVersion()->exists()) {
            $effectiveAt ??= now()->addDays((int) $this->settings->get('contracts.notice_default_days', 14));

            if ($effectiveAt->isFuture()) {
                $version->forceFill(['status' => ContractVersionStatus::Scheduled, 'effective_at' => $effectiveAt, 'published_by' => $adminUserId])->save();

                return $version;
            }
        }

        return $this->makeCurrent($version, $adminUserId, continuedUse: false);
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
            $template->versions()->where('status', ContractVersionStatus::Published)->where('id', '!=', $version->id)
                ->update(['status' => ContractVersionStatus::Retired, 'retired_at' => now()]);
            $version->forceFill(['status' => ContractVersionStatus::Published, 'published_at' => now(), 'published_by' => $adminUserId])->save();
        });

        foreach ($this->countriesOf($template) as $country) {
            $this->countryContext->runAs($country, function () use ($version, $template, $continuedUse): void {
                $stale = ContractSignature::query()
                    ->where('contract_template_id', $template->id)
                    ->where('contract_version_id', '!=', $version->id)
                    ->where('status', ContractSignature::STATUS_ACTIVE)
                    ->get();

                foreach ($stale as $signature) {
                    if ($continuedUse) {
                        $this->signer->sign($version->refresh(), $signature->signer_type, $signature->signer_id, $signature->signer_user_id, $signature->full_name, SignatureMethod::ContinuedUse, subjectType: $signature->subject_type, subjectId: $signature->subject_id, locale: $signature->locale);
                    } else {
                        $signature->forceFill(['status' => ContractSignature::STATUS_SUPERSEDED, 'superseded_at' => now()])->save();
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
