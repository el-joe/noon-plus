<?php

namespace App\Domains\Content\Support;

use App\Domains\Content\Models\NotificationTemplate;
use App\Domains\Content\Models\NotificationTemplateVersion;
use App\Domains\Countries\Models\Country;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Notification texts per country × language (ADR 10): the country's
 * template, else the global one, else the built-in text. A template that
 * is switched off sends nothing on that channel.
 */
class NotificationTemplates
{
    private const PLACEHOLDER = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /**
     * @param  array<string, string|int|float|null>  $variables
     * @return array{subject: string|null, body: string}|null
     */
    public function render(string $event, string $channel, string $locale, array $variables, ?Country $country = null): ?array
    {
        $definition = NotificationEvents::find($event);

        if ($definition === null) {
            return null;
        }

        $template = $this->stored($event, $channel, $locale, $country);

        if ($template !== null && ! $template->is_active) {
            return null;
        }

        $text = $template !== null
            ? ['subject' => $template->subject, 'body' => $template->body]
            : ($definition['defaults'][$locale] ?? $definition['defaults']['en']);

        return [
            'subject' => $text['subject'] === null ? null : $this->fill($text['subject'], $variables),
            'body' => $this->fill($text['body'], $variables),
        ];
    }

    /**
     * The saved template for this exact scope (country or global), if any.
     */
    public function stored(string $event, string $channel, string $locale, ?Country $country): ?NotificationTemplate
    {
        $query = NotificationTemplate::query()->where('event', $event)->where('channel', $channel)->where('locale', $locale);

        if ($country !== null) {
            $own = (clone $query)->where('country_id', $country->id)->first();

            if ($own !== null) {
                return $own;
            }
        }

        return $query->whereNull('country_id')->first();
    }

    /**
     * Save a template; every `{{ variable }}` must belong to the event.
     *
     * @param  array{subject?: string|null, body: string, is_active?: bool, action?: string}  $data
     */
    public function save(string $event, string $channel, string $locale, array $data, ?Country $country, ?int $userId): NotificationTemplate
    {
        $definition = NotificationEvents::find($event);

        if ($definition === null || ! in_array($channel, NotificationEvents::CHANNELS, true) || ! in_array($locale, NotificationEvents::LOCALES, true)) {
            throw ValidationException::withMessages(['event' => __('Unknown notification event, channel or language.')]);
        }

        $unknown = array_diff($this->variablesIn(($data['subject'] ?? '').' '.$data['body']), $definition['variables']);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => __('Unknown variables: :list. Use: :allowed.', [
                'list' => implode(', ', $unknown),
                'allowed' => implode(', ', $definition['variables']),
            ])]);
        }

        $version = $this->nextVersion($event, $channel, $locale, $country);
        $template = NotificationTemplate::query()->updateOrCreate(
            ['country_id' => $country?->id, 'event' => $event, 'channel' => $channel, 'locale' => $locale],
            ['subject' => $data['subject'] ?? null, 'body' => $data['body'], 'is_active' => $data['is_active'] ?? true, 'version' => $version, 'updated_by' => $userId],
        );

        NotificationTemplateVersion::query()->create([
            'country_id' => $country?->id, 'event' => $event, 'channel' => $channel, 'locale' => $locale, 'version' => $version,
            'action' => $data['action'] ?? 'saved', 'subject' => $template->subject, 'body' => $template->body, 'is_active' => $template->is_active, 'created_by' => $userId,
        ]);

        return $template;
    }

    /**
     * Back to the built-in text for this scope (the saved template is removed; history keeps it).
     */
    public function reset(string $event, string $channel, string $locale, ?Country $country, ?int $userId): bool
    {
        $deleted = NotificationTemplate::query()->where('country_id', $country?->id)->where('event', $event)->where('channel', $channel)->where('locale', $locale)->delete() > 0;

        if ($deleted) {
            NotificationTemplateVersion::query()->create([
                'country_id' => $country?->id, 'event' => $event, 'channel' => $channel, 'locale' => $locale,
                'version' => $this->nextVersion($event, $channel, $locale, $country), 'action' => 'reset', 'created_by' => $userId,
            ]);
        }

        return $deleted;
    }

    /**
     * Save an earlier version again as the newest one.
     */
    public function restore(NotificationTemplateVersion $version, ?Country $country, ?int $userId): NotificationTemplate
    {
        if ($version->body === null) {
            throw ValidationException::withMessages(['version' => __('A reset cannot be restored; use Reset to default instead.')]);
        }

        return $this->save($version->event, $version->channel, $version->locale, ['subject' => $version->subject, 'body' => $version->body, 'is_active' => $version->is_active, 'action' => 'restored'], $country, $userId);
    }

    /**
     * @return Collection<int, NotificationTemplateVersion>
     */
    public function versions(string $event, string $channel, string $locale, ?Country $country): Collection
    {
        return NotificationTemplateVersion::query()->where('country_id', $country?->id)->where('event', $event)->where('channel', $channel)->where('locale', $locale)->latest('version')->limit(30)->get();
    }

    private function nextVersion(string $event, string $channel, string $locale, ?Country $country): int
    {
        return (int) NotificationTemplateVersion::query()->where('country_id', $country?->id)->where('event', $event)->where('channel', $channel)->where('locale', $locale)->max('version') + 1;
    }

    /**
     * @return array<int, string>
     */
    public function variablesIn(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * @param  array<string, string|int|float|null>  $variables
     */
    private function fill(string $text, array $variables): string
    {
        return preg_replace_callback(self::PLACEHOLDER, fn (array $match): string => (string) ($variables[strtolower($match[1])] ?? ''), $text);
    }
}
