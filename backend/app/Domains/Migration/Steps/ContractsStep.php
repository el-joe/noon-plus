<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Contracts\Support\ContractRenderer;
use App\Domains\Migration\Support\LegacyFiles;
use App\Domains\Migration\Support\MigrationContext;
use Illuminate\Support\Facades\Storage;

/**
 * Contracts (MG6, plan §6 "Contracts"): legacy contract templates become
 * central templates with one version each (files copied to the private
 * disk); every vendor signature is re-verified against its legacy SHA-256
 * before it moves — editor contracts hash the rendered text + the signing
 * variables (in the order the old platform built them), file contracts hash
 * the signed document. A signature that does not verify (or whose file is
 * missing / unreadable) is quarantined. The migrated signature keeps a
 * frozen copy whose SHA-256 the new ContractSigner::verify() checks.
 *
 * Template versions arrive as **drafts** for admin review, whatever their
 * legacy publish state: a published global template (e.g. a legacy Seller
 * Agreement) would otherwise apply at once in every country and send every
 * store to re-sign. Publishing the draft later (ContractPublisher) keeps the
 * migrated signatures of that same version valid.
 */
class ContractsStep extends Step
{
    /**
     * Key order of the old CategoryContractService::variablesFor().
     *
     * @var array<int, string>
     */
    public const VARIABLE_ORDER = [
        'vendor.store_name', 'vendor.business_name', 'vendor.business_type', 'vendor.registration_number', 'vendor.tax_id',
        'vendor.contact_email', 'vendor.contact_phone', 'vendor.address', 'vendor.country', 'vendor.signer_name',
        'platform.name_en', 'platform.name_ar', 'category.name_en', 'category.name_ar', 'contract.version', 'contract.date',
    ];

    /**
     * @var array<string, string>
     */
    public const METHODS = ['registration' => 'registration', 'click_wrap' => 'click', 'implicit' => 'continued_use'];

    public function name(): string
    {
        return 'contracts';
    }

    protected function order(): array
    {
        return ['classified_contract_templates', 'vendor_contracts'];
    }

    protected function migrateClassifiedContractTemplates(string $table): void
    {
        $files = new LegacyFiles;

        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $countryCode = $this->countries->code($row['country_id'] === null ? null : (string) $row['country_id']);

            if ($row['country_id'] !== null && $countryCode === null) {
                $this->quarantine->put($table, $id, 'country_unresolved', 'template country is not launched', null, $row);

                continue;
            }

            $paths = [];

            foreach ((array) json_decode((string) $row['files'], true) as $locale => $file) {
                if (! is_array($file) || ! isset($file['path'])) {
                    continue;
                }

                $target = 'contracts/templates/legacy/'.$id.'/'.$locale.'.'.(pathinfo((string) $file['path'], PATHINFO_EXTENSION) ?: 'pdf');
                $copied = $this->context->dryRun ? true : $files->copy('private', (string) $file['path'], ContractRenderer::DISK, $target, $file['sha256'] ?? null);

                if ($copied !== true) {
                    $this->quarantine->put($table, $id, 'file_'.$copied, "template file {$file['path']}", $countryCode, $row);

                    continue 2;
                }

                $paths[$locale] = [$target, $file['mime'] ?? 'application/pdf'];
            }

            $templateId = $this->writer->upsert($table, $id, MigrationContext::CENTRAL, 'contract_templates', [
                'legacy_id' => $id,
                'country_id' => $countryCode === null ? null : $this->centralCountryId($countryCode),
                'kind' => (string) $row['contract_kind'],
                'name' => (string) $row['name'],
                'mode' => $row['content_mode'] === 'file' ? 'file' : 'editor',
                'consent_mode' => $row['acceptance_mode'] === 'notice' ? 'notice' : 'click_wrap',
                'is_default' => (bool) $row['is_country_default'],
                ...$this->timestamps($row),
            ]);

            $this->writer->upsert($table, $id.':v'.$row['version'], MigrationContext::CENTRAL, 'contract_versions', [
                'contract_template_id' => $templateId,
                'version' => max(1, (int) $row['version']),
                'status' => 'draft',
                'body_en' => $row['content_en'],
                'body_ar' => $row['content_ar'],
                'file_path_en' => $paths['en'][0] ?? null,
                'file_path_ar' => $paths['ar'][0] ?? null,
                'file_mime' => ($paths['en'] ?? $paths['ar'] ?? [null, null])[1],
                'change_summary' => mb_substr((string) ($row['change_summary'] ?? ($row['is_published'] ? 'Imported from the legacy platform, where it was published.' : 'Imported from the legacy platform.')), 0, 255),
                'effective_at' => null,
                'published_at' => null,
                ...$this->timestamps($row),
            ], countMetrics: false);
        }
    }

    protected function migrateVendorContracts(string $table): void
    {
        $files = new LegacyFiles;

        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $template = $this->source->find('classified_contract_templates', (string) $row['contract_template_id']);
            $templateId = $this->ids->find('classified_contract_templates', (string) $row['contract_template_id'], MigrationContext::CENTRAL, 'contract_templates');
            $versionId = $this->ids->find('classified_contract_templates', $row['contract_template_id'].':v'.($template['version'] ?? 1), MigrationContext::CENTRAL, 'contract_versions');

            if ($template === null || $templateId === null || $versionId === null) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', 'contract template not migrated', null, $row);

                continue;
            }

            $vendorTargets = $this->ids->targets('vendors', (string) $row['vendor_id'], 'vendors');
            $code = $this->countries->code($row['country_id'] === null ? null : (string) $row['country_id']) ?? array_key_first($vendorTargets);

            if ($code === null || ! isset($vendorTargets[$code])) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', 'vendor not migrated', $code, $row);

                continue;
            }

            if (! $this->context->allows($code)) {
                continue;
            }

            if ($row['content_mode'] === 'file') {
                $bytes = $files->read('private', (string) $row['signed_file_path']);

                if ($bytes === null) {
                    $this->quarantine->put($table, $id, 'file_'.$files->error, "signed file {$row['signed_file_path']}", $code, $row);

                    continue;
                }

                $extension = pathinfo((string) $row['signed_file_path'], PATHINFO_EXTENSION) ?: 'pdf';
            } else {
                $bytes = (string) $row['rendered_content'];
                $extension = 'html';
            }

            if (! self::verifies($row, $bytes)) {
                $this->quarantine->put($table, $id, 'signature_hash_mismatch', 'legacy SHA-256 does not verify', $code, $row);

                continue;
            }

            $path = sprintf('contracts/signatures/%s/%s/legacy-%s.%s', $code, $template['contract_kind'], $id, $extension);

            if (! $this->context->dryRun && Storage::disk(ContractRenderer::DISK)->get($path) !== $bytes) {
                Storage::disk(ContractRenderer::DISK)->put($path, $bytes);
            }

            $signerUserId = $row['signed_by_vendor_admin_id'] ? $this->ids->find('vendor_admins', (string) $row['signed_by_vendor_admin_id'], MigrationContext::CENTRAL, 'users') : null;
            $subjectId = $row['product_category_id'] ? $this->ids->find('categories', (string) $row['product_category_id'], $code, 'categories') : null;

            $this->writer->upsert($table, $id, $code, 'contract_signatures', [
                'contract_template_id' => $templateId,
                'contract_version_id' => $versionId,
                'version' => max(1, (int) $row['template_version']),
                'kind' => (string) $template['contract_kind'],
                'signer_type' => 'vendor',
                'signer_id' => $vendorTargets[$code],
                'signer_user_id' => $signerUserId,
                'subject_type' => $subjectId === null ? null : 'category',
                'subject_id' => $subjectId,
                'full_name' => mb_substr((string) ($row['signer_name'] ?: 'Legacy signer'), 0, 160),
                'locale' => $row['language_signed'] === 'ar' ? 'ar' : 'en',
                'method' => self::METHODS[$row['acceptance_method']] ?? 'click',
                'ip_address' => $row['signed_ip'],
                'user_agent' => $row['signed_user_agent'],
                'frozen_path' => $path,
                'sha256' => hash('sha256', $bytes),
                'status' => $row['status'] === 'active' ? 'active' : ($row['status'] === 'superseded' ? 'superseded' : 'revoked'),
                'signed_at' => $row['signed_at'] ?? $row['created_at'],
                'superseded_at' => $row['status'] === 'superseded' ? $row['updated_at'] : null,
                ...$this->timestamps($row),
            ]);
        }
    }

    /**
     * The legacy signature hash: the signed file's SHA-256, or SHA-256 of the
     * rendered text + JSON of the variables in the old builder's key order.
     *
     * @param  array<string, mixed>  $row
     */
    public static function verifies(array $row, string $bytes): bool
    {
        $expected = (string) $row['rendered_content_hash'];

        if ($row['content_mode'] === 'file') {
            return hash_equals($expected, hash('sha256', $bytes));
        }

        $variables = (array) json_decode((string) $row['variables'], true);
        $ordered = [];

        foreach (self::VARIABLE_ORDER as $key) {
            if (array_key_exists($key, $variables)) {
                $ordered[$key] = $variables[$key];
            }
        }

        $ordered += $variables;

        foreach ([$ordered, $variables] as $candidate) {
            if (hash_equals($expected, hash('sha256', $bytes.json_encode($candidate)))) {
                return true;
            }
        }

        return false;
    }

    private function centralCountryId(string $code): int
    {
        return (int) MigrationContext::db(MigrationContext::CENTRAL)->table('countries')->where('code', $code)->value('id');
    }
}
