<?php

namespace App\Domains\Reviews\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Settings\SettingsService;

/**
 * Keyword auto-flagging for new reviews and vendor replies (lifecycle
 * §2.9, QC-012). Setting `reviews.auto_flag_rules` (global or per country):
 * `{"words": [...], "flag_links": true}`; defaults in `nawy.reviews`.
 * Words match whole words, case-insensitively, in any script.
 */
class ReviewAutoFlag
{
    public function __construct(private SettingsService $settings, private CountryContext $countryContext) {}

    /**
     * Why the text is flagged, or null when it is clean.
     */
    public function reason(?string ...$texts): ?string
    {
        $text = trim(implode("\n", array_filter($texts)));

        if ($text === '') {
            return null;
        }

        $rules = $this->rules();

        foreach ($rules['words'] as $word) {
            $word = trim((string) $word);

            if ($word !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $text) === 1) {
                return __('Contains a blocked word: :word', ['word' => $word]);
            }
        }

        if ($rules['flag_links'] && preg_match('#(https?://|www\.)\S+#iu', $text) === 1) {
            return __('Contains a link');
        }

        return null;
    }

    /**
     * @return array{words: array<int, string>, flag_links: bool}
     */
    public function rules(): array
    {
        $stored = $this->settings->get('reviews.auto_flag_rules', null, $this->countryContext->has() ? $this->countryContext->current() : null);
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;
        $defaults = config('nawy.reviews.auto_flag_rules');

        return [
            'words' => array_values((array) ($stored['words'] ?? $defaults['words'])),
            'flag_links' => (bool) ($stored['flag_links'] ?? $defaults['flag_links']),
        ];
    }
}
