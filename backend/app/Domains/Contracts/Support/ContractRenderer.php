<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Contracts\Models\ContractVersion;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a version for reading and for the frozen evidence copy. Editor
 * mode replaces `{variable}` placeholders (store name, signer name, date,
 * country…); file mode returns the uploaded file untouched — variables do
 * not apply and the signer's name and date live on the signature (SRS §13.2).
 */
class ContractRenderer
{
    public const DISK = 'local';

    /**
     * @param  array<string, string|null>  $variables
     */
    public function text(ContractVersion $version, string $locale, array $variables = []): string
    {
        $body = $locale === 'ar' ? ($version->body_ar ?? $version->body_en) : ($version->body_en ?? $version->body_ar);
        $replacements = [];

        foreach ($variables as $name => $value) {
            $replacements['{'.$name.'}'] = (string) $value;
        }

        return strtr((string) $body, $replacements);
    }

    /**
     * The bytes frozen on a signature and their extension.
     *
     * @param  array<string, string|null>  $variables
     * @return array{0: string, 1: string}
     */
    public function frozenCopy(ContractVersion $version, string $locale, array $variables, string $signatureBlock): array
    {
        if ($version->isFile()) {
            $path = $locale === 'en' && $version->file_path_en !== null ? $version->file_path_en : ($version->file_path_ar ?? $version->file_path_en);

            return [(string) Storage::disk(self::DISK)->get($path), pathinfo($path, PATHINFO_EXTENSION) ?: 'pdf'];
        }

        $template = $version->template;
        $html = '<!DOCTYPE html><html lang="'.e($locale).'" dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"><head><meta charset="utf-8"><title>'
            .e($template->name).' v'.$version->version.'</title></head><body><h1>'.e($template->name).' — v'.$version->version.'</h1>'
            .'<div class="contract-body">'.nl2br(e($this->text($version, $locale, $variables))).'</div>'
            .'<hr><div class="signature">'.$signatureBlock.'</div></body></html>';

        return [$html, 'html'];
    }
}
