<?php

namespace App\Shared\Notifications\Email;

use App\Domains\Content\Support\NotificationTemplates;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Shared\Localization\InLocale;

/**
 * Renders a catalogue email in the recipient's language: the editable
 * subject and body (country template → global template → built-in text),
 * structured blocks from the caller (receipt lines, totals, address, code),
 * the button, and the branded layout (RTL for Arabic) with a plain-text
 * alternative. Mail that can be turned off gets the unsubscribe footer.
 *
 * Blocks (`options.blocks`), labels are translation keys:
 *  - `['type' => 'table', 'columns' => [...], 'rows' => [[...], ...]]`
 *  - `['type' => 'totals', 'rows' => [[label, value], ...]]` (last row emphasised)
 *  - `['type' => 'facts', 'title' => label, 'rows' => [[label, value], ...]]`
 *  - `['type' => 'code', 'value' => '482913']`
 *  - `['type' => 'note', 'text' => key, 'replace' => [...]]`
 */
class EmailRenderer
{
    public function __construct(
        private NotificationTemplates $templates,
        private CountryContext $countryContext,
        private EmailLinks $links,
        private UnsubscribeTokens $tokens,
    ) {}

    /**
     * @param  array<string, mixed>  $variables  strings or {@see EmailValue} values
     * @param  array<string, mixed>  $options
     * @param  array{email?: string|null, user_id?: int|null, log_id?: int|null, template?: array{subject: string, body: string}|null}  $context
     */
    public function render(string $event, string $locale, array $variables, array $options = [], array $context = []): ?RenderedEmail
    {
        $definition = EmailCatalogue::get($event);
        $locale = in_array($locale, ['en', 'ar'], true) ? $locale : 'en';
        $country = $this->country($options['country'] ?? null);

        $work = fn (): ?RenderedEmail => InLocale::run($locale, fn (): ?RenderedEmail => $this->build($event, $definition, $locale, $variables, $options, $context));

        if ($country !== null && (! $this->countryContext->has() || $this->countryContext->current()->id !== $country->id)) {
            return $this->countryContext->runAs($country, $work);
        }

        return $work();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $context
     */
    private function build(string $event, array $definition, string $locale, array $variables, array $options, array $context): ?RenderedEmail
    {
        $country = $this->countryContext->has() ? $this->countryContext->current() : null;
        $variables = array_map(fn ($value): string => EmailValue::resolve($value), $variables);

        $override = $context['template'] ?? $options['template_override'] ?? null;

        if (is_array($override)) {
            $text = ['subject' => $this->fill((string) ($override['subject'] ?? ''), $variables), 'body' => $this->fill((string) ($override['body'] ?? ''), $variables)];
            $version = 0;
        } else {
            $text = $this->templates->render($event, 'mail', $locale, $variables, $country);

            if ($text === null) {
                return null;
            }

            $version = $this->templates->stored($event, 'mail', $locale, $country)?->version ?? 0;
        }

        /** @var EmailCategory $category */
        $category = $definition['category'];
        $panel = EmailLinks::panelFor($definition['actor'], $options['panel'] ?? null);
        $email = (string) ($context['email'] ?? '');
        $unsubscribe = null;
        $oneClick = null;
        $preferences = null;

        if (! $category->isLocked() && $email !== '') {
            $token = $this->tokens->make(EmailAddress::hash($email), EmailPreferences::topic($event), $context['user_id'] ?? null, $context['log_id'] ?? null);
            $links = $this->links->preferenceLinks($panel, $locale, $token);
            $unsubscribe = $links['unsubscribe'];
            $preferences = $links['preferences'];
            $oneClick = route('api.v1.email.unsubscribe.one-click', ['token' => $token]);
        }

        $button = null;

        if (! empty($options['url']) && is_array($definition['button'])) {
            $button = ['label' => $definition['button'][$locale === 'ar' ? 1 : 0], 'url' => (string) $options['url']];
        }

        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n{2,}/', str_replace("\r\n", "\n", $text['body'])) ?: []), fn (string $paragraph): bool => $paragraph !== ''));
        $blocks = $this->blocks($options['blocks'] ?? []);
        $footer = [
            'help' => $panel === null ? $this->links->storefront('help', $locale) : $this->links->panel($panel, 'support'),
            'terms' => $this->links->storefront('pages/terms-and-conditions', $locale),
            'privacy' => $this->links->storefront('pages/privacy-policy', $locale),
            'preferences' => $preferences,
            'unsubscribe' => $unsubscribe,
            'country' => $country === null ? null : ($locale === 'ar' ? $country->name_ar : $country->name_en),
            'reason' => $category->isLocked() ? __('You receive this email because of activity on your Nawy account.') : __('You receive this email because you opted in to :topic.', ['topic' => mb_strtolower($category->label())]),
        ];
        $data = [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'subject' => (string) $text['subject'],
            'preheader' => mb_substr(strip_tags($paragraphs[1] ?? $paragraphs[0] ?? ''), 0, 140),
            'paragraphs' => $paragraphs,
            'blocks' => $blocks,
            'button' => $button,
            'footer' => $footer,
            'brand' => $locale === 'ar' ? 'ناوي' : 'Nawy',
        ];

        return new RenderedEmail(
            (string) $text['subject'],
            view('emails.event', $data)->render(),
            trim(view('emails.event-text', $data)->render()),
            (int) $version,
            $locale,
            $unsubscribe,
            $oneClick,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $blocks): array
    {
        $label = fn ($value): string => is_string($value) ? __($value) : (string) $value;

        return array_values(array_filter(array_map(function (array $block) use ($label): ?array {
            return match ($block['type'] ?? null) {
                'table' => ['type' => 'table', 'columns' => array_map($label, $block['columns'] ?? []), 'rows' => array_map(fn (array $row): array => array_map(fn ($cell): string => EmailValue::resolve($cell), $row), $block['rows'] ?? [])],
                'totals' => ['type' => 'totals', 'rows' => array_map(fn (array $row): array => [$label($row[0]), EmailValue::resolve($row[1])], $block['rows'] ?? [])],
                'facts' => ['type' => 'facts', 'title' => isset($block['title']) ? $label($block['title']) : null, 'rows' => array_map(fn (array $row): array => [$label($row[0]), EmailValue::resolve($row[1])], $block['rows'] ?? [])],
                'code' => ['type' => 'code', 'value' => (string) ($block['value'] ?? '')],
                'note' => ['type' => 'note', 'text' => __((string) ($block['text'] ?? ''), $block['replace'] ?? [])],
                default => null,
            };
        }, $blocks)));
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function fill(string $text, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn (array $match): string => $variables[strtolower($match[1])] ?? '', $text);
    }

    private function country(?string $code): ?Country
    {
        if ($code === null || $code === '') {
            return $this->countryContext->has() ? $this->countryContext->current() : null;
        }

        return Country::query()->where('code', $code)->first();
    }
}
