<?php

namespace App\Domains\Content\Support;

use App\Domains\Countries\Models\Country;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Block settings between storage, the admin editor and the storefront,
 * driven by the {@see BlockSchemas}:
 *
 * - content fields (`translatable`: texts, rich text, keywords, images with
 *   their alt text, links) are stored as `{"en": …, "ar": …}`; a missing or
 *   empty side falls back to the other one ({@see self::localize()}, used by
 *   {@see BlockResolver} so the storefront keeps receiving plain values);
 * - older settings (a plain string or URL, or the `{key}_en` / `{key}_ar`
 *   pair) are read as bilingual values without data loss
 *   ({@see self::bilingual()}): plain text belongs to the page's language,
 *   a plain image or link to both;
 * - date-times are edited in country time and stored in UTC (ISO 8601);
 * - fields hidden by their conditions are dropped, keys the schema does not
 *   know are kept untouched, empty values are removed.
 *
 * Validation errors are collected per field path
 * (`zones.main.0.settings.slides.1.link.ar`) so the editor shows them inline.
 */
class BlockSettings
{
    public const LOCALES = ['en', 'ar'];

    private const MEDIA_TYPES = ['image', 'link'];

    private const MAX_TEXT = 500;

    private const MAX_LONG_TEXT = 50000;

    /**
     * @var array<string, string>
     */
    private array $errors = [];

    public function __construct(private string $locale, private ?Country $country = null) {}

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Stored settings → editor values (bilingual objects, local date-times).
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function toEditor(string $type, array $settings): array
    {
        return $this->walk(BlockSchemas::for($type), $this->bilingual($type, $settings), fn (BlockField $field, mixed $value): mixed => match ($field->type()) {
            'datetime' => $this->toLocal($value),
            'datetime_range' => is_array($value) ? [...$value, 'starts_at' => $this->toLocal($value['starts_at'] ?? null), 'ends_at' => $this->toLocal($value['ends_at'] ?? null)] : $value,
            default => $value,
        });
    }

    /**
     * Editor values → settings to store (errors collected under `$path`).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function fromEditor(string $type, array $input, string $path): array
    {
        return $this->storedValues(BlockSchemas::for($type), $input, $path);
    }

    /**
     * Older settings in the bilingual shape: `{key}_en` / `{key}_ar` pairs
     * and plain values of translatable fields become `{en, ar}` objects.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function bilingual(string $type, array $settings): array
    {
        return $this->convertLegacy(BlockSchemas::for($type), $settings);
    }

    /**
     * Resolve every `{en, ar}` value for one language (falling back to the
     * other side when empty), at any depth: what the storefront receives.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function localize(array $settings, string $locale): array
    {
        foreach ($settings as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $settings[$key] = self::isTranslation($value) ? self::pick($value, $locale) : self::localize($value, $locale);
        }

        return $settings;
    }

    /**
     * Whether a value is an `{en, ar}` translation object.
     */
    public static function isTranslation(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value) && array_diff(array_keys($value), self::LOCALES) === [];
    }

    /**
     * One language of a translation object, else the other one.
     *
     * @param  array<string, mixed>  $value
     */
    public static function pick(array $value, string $locale): mixed
    {
        $other = $locale === 'ar' ? 'en' : 'ar';
        $filled = fn (mixed $side): bool => $side !== null && $side !== '' && $side !== [];

        return $filled($value[$locale] ?? null) ? $value[$locale] : ($value[$other] ?? null);
    }

    /**
     * Content filled in one language but missing in the other
     * (`["slides.1.label: ar", …]`); images and links are excluded since
     * they fall back on purpose ("same for both").
     *
     * @param  array<string, mixed>  $settings
     * @return array<int, string>
     */
    public function gaps(string $type, array $settings): array
    {
        $gaps = [];
        $this->collectGaps(BlockSchemas::for($type), $this->bilingual($type, $settings), '', $gaps);

        return $gaps;
    }

    /**
     * Whether a field is shown for these sibling values (`show_if`).
     *
     * @param  array<string, mixed>  $values
     */
    public static function visible(BlockField $field, array $values): bool
    {
        foreach ((array) $field->get('show_if', []) as $key => $expected) {
            $actual = self::conditionValue($values, (string) $key);

            if (is_array($expected)) {
                if (! in_array($actual, array_map('strval', $expected), true)) {
                    return false;
                }

                continue;
            }

            $expected = (string) $expected;

            if ($expected === '!') {
                if ($actual === '') {
                    return false;
                }
            } elseif (str_starts_with($expected, '!')) {
                if ($actual === substr($expected, 1)) {
                    return false;
                }
            } elseif ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function conditionValue(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (self::isTranslation($value)) {
            $value = self::pick($value, 'en');
        }

        return is_scalar($value) ? (is_bool($value) ? ($value ? '1' : '') : (string) $value) : '';
    }

    /**
     * Apply `$map` to the value of every shown field (recursing into groups,
     * repeaters and child blocks).
     *
     * @param  array<int, BlockField>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function walk(array $fields, array $values, callable $map): array
    {
        foreach ($this->shownFields($fields, $values) as $field) {
            $key = $field->key();

            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $map($field, $values[$key]);
            $values[$key] = match ($field->type()) {
                'group' => is_array($value) ? $this->walk($field->fields(), $value, $map) : $value,
                'repeater' => is_array($value) ? array_map(fn ($item) => is_array($item) ? $this->walk($field->fields(), $item, $map) : $item, array_values($value)) : $value,
                'block' => is_array($value) ? $this->walkChild($value, $map) : $value,
                'blocks' => is_array($value) ? array_map(fn ($child) => is_array($child) ? $this->walkChild($child, $map) : $child, array_values($value)) : $value,
                default => $value,
            };

            if ($values[$key] === null) {
                unset($values[$key]);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    private function walkChild(array $child, callable $map): array
    {
        $type = (string) ($child['type'] ?? '');
        $child['settings'] = $this->walk(BlockSchemas::for($type), is_array($child['settings'] ?? null) ? $child['settings'] : [], $map);

        return $child;
    }

    /**
     * @param  array<int, BlockField>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function convertLegacy(array $fields, array $values): array
    {
        foreach ($fields as $field) {
            $key = $field->key();

            if ($field->get('translatable')) {
                $values = $this->foldLocales($values, $key, in_array($field->type(), self::MEDIA_TYPES, true));
            }

            if ($field->get('alt')) {
                $values = $this->foldLocales($values, $field->get('alt'), false);
            }

            $value = $values[$key] ?? null;

            if (! is_array($value)) {
                continue;
            }

            $values[$key] = match ($field->type()) {
                'group' => $this->convertLegacy($field->fields(), $value),
                'repeater' => array_map(fn ($item) => is_array($item) ? $this->convertLegacy($field->fields(), $item) : $item, array_values($value)),
                'block' => $this->convertChild($value),
                'blocks' => array_map(fn ($child) => is_array($child) ? $this->convertChild($child) : $child, array_values($value)),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    private function convertChild(array $child): array
    {
        $child['settings'] = $this->bilingual((string) ($child['type'] ?? ''), is_array($child['settings'] ?? null) ? $child['settings'] : []);

        return $child;
    }

    /**
     * Fold `{key}`, `{key}_en` and `{key}_ar` into one `{en, ar}` value.
     * A plain value is text of the page language, or (media) both languages.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function foldLocales(array $values, string $key, bool $media): array
    {
        $current = $values[$key] ?? null;

        if (self::isTranslation($current) && ! array_key_exists("{$key}_en", $values) && ! array_key_exists("{$key}_ar", $values)) {
            return $values;
        }

        $translation = self::isTranslation($current) ? $current : [];

        if (! self::isTranslation($current) && $current !== null && $current !== '' && $current !== []) {
            $translation[$media ? 'en' : $this->locale] = $current;
        }

        foreach (self::LOCALES as $locale) {
            if (array_key_exists("{$key}_{$locale}", $values)) {
                $side = $values["{$key}_{$locale}"];

                if ($side !== null && $side !== '' && $side !== []) {
                    $translation[$locale] = $side;
                }

                unset($values["{$key}_{$locale}"]);
            }
        }

        if ($translation === []) {
            unset($values[$key]);
        } else {
            $values[$key] = array_intersect_key(array_merge(array_fill_keys(self::LOCALES, null), $translation), $translation);
        }

        return $values;
    }

    /**
     * @param  array<int, BlockField>  $fields
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $gaps
     */
    private function collectGaps(array $fields, array $values, string $prefix, array &$gaps): void
    {
        foreach ($this->shownFields($fields, $values) as $field) {
            $key = $field->key();
            $value = $values[$key] ?? null;
            $label = $prefix.$key;

            if ($field->get('translatable') && ! in_array($field->type(), self::MEDIA_TYPES, true) && self::isTranslation($value)) {
                foreach (self::LOCALES as $locale) {
                    $other = $locale === 'en' ? 'ar' : 'en';
                    $filled = fn (mixed $side): bool => $side !== null && $side !== '' && $side !== [];

                    if ($filled($value[$other] ?? null) && ! $filled($value[$locale] ?? null)) {
                        $gaps[] = "{$label}:{$locale}";
                    }
                }
            }

            if (! is_array($value)) {
                continue;
            }

            match ($field->type()) {
                'group' => $this->collectGaps($field->fields(), $value, "{$label}.", $gaps),
                'repeater' => array_map(fn ($item, $index) => is_array($item) ? $this->collectGaps($field->fields(), $item, "{$label}.{$index}.", $gaps) : null, array_values($value), array_keys(array_values($value))),
                'block' => $this->collectGaps(BlockSchemas::for((string) ($value['type'] ?? '')), (array) ($value['settings'] ?? []), "{$label}.", $gaps),
                'blocks' => array_map(fn ($child, $index) => is_array($child) ? $this->collectGaps(BlockSchemas::for((string) ($child['type'] ?? '')), (array) ($child['settings'] ?? []), "{$label}.{$index}.", $gaps) : null, array_values($value), array_keys(array_values($value))),
                default => null,
            };
        }
    }

    /**
     * Fields shown for these values: the first shown field of a key wins (two
     * variants of a field may share a key, e.g. image slides vs styled cards).
     *
     * @param  array<int, BlockField>  $fields
     * @param  array<string, mixed>  $values
     * @return array<int, BlockField>
     */
    private function shownFields(array $fields, array $values): array
    {
        $shown = [];

        foreach ($fields as $field) {
            if (! isset($shown[$field->key()]) && self::visible($field, $values)) {
                $shown[$field->key()] = $field;
            }
        }

        return array_values($shown);
    }

    /**
     * @param  array<int, BlockField>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function storedValues(array $fields, array $input, string $path): array
    {
        $input = $this->convertLegacy($fields, $input);
        $shown = $this->shownFields($fields, $input);
        $shownKeys = array_map(fn (BlockField $field): string => $field->key(), $shown);
        $out = $input;

        foreach ($fields as $field) {
            if (! in_array($field->key(), $shownKeys, true)) {
                unset($out[$field->key()]);
            }
        }

        foreach ($shown as $field) {
            $this->store($field, $input, $out, $path);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $out
     */
    private function store(BlockField $field, array $input, array &$out, string $path): void
    {
        $key = $field->key();
        $at = "{$path}.{$key}";
        $value = $input[$key] ?? null;

        $stored = $field->get('translatable')
            ? $this->translation($value, fn (mixed $side, string $locale): mixed => $this->single($field, $side, "{$at}.{$locale}"), $at)
            : $this->single($field, $value, $at);

        if ($stored === null || $stored === '' || $stored === []) {
            unset($out[$key]);
        } else {
            $out[$key] = $stored;

            foreach ((array) $field->get('clears', []) as $cleared) {
                unset($out[$cleared]);
            }
        }

        if ($field->get('alt')) {
            $alt = $field->get('alt');
            $altField = BlockField::text($alt, 'Alt text');
            $text = $this->translation($input[$alt] ?? null, fn (mixed $side, string $locale): mixed => $this->text($side, $altField, "{$path}.{$alt}.{$locale}"), "{$path}.{$alt}");

            if ($text === null) {
                unset($out[$alt]);
            } else {
                $out[$alt] = $text;
            }
        }
    }

    /**
     * A translatable value: `{en, ar}` with empty sides removed.
     *
     * @return array<string, mixed>|null
     */
    private function translation(mixed $value, callable $side, string $at): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (! self::isTranslation($value)) {
            return $this->fail($at, __('Enter the English and Arabic values.'));
        }

        $translation = [];

        foreach (self::LOCALES as $locale) {
            $stored = $side($value[$locale] ?? null, $locale);

            if ($stored !== null && $stored !== '' && $stored !== []) {
                $translation[$locale] = $stored;
            }
        }

        return $translation === [] ? null : $translation;
    }

    /**
     * One value of a field (one language of a translatable one).
     */
    private function single(BlockField $field, mixed $value, string $at): mixed
    {
        return match ($field->type()) {
            'text', 'textarea', 'rich_text' => $this->text($value, $field, $at),
            'number' => $this->number($value, $field, $at),
            'select' => $this->choice($value, $field, $at),
            'toggle' => $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL),
            'checkbox_group' => $this->choices($value, $field, $at),
            'color' => $this->color($value, $at),
            'datetime' => $this->toUtc($value, $at),
            'datetime_range' => $this->range($value, $at),
            'image' => $this->url($value, $at, false),
            'link' => $this->url($value, $at, true),
            'tags' => $this->tags($value, $at),
            'group' => $this->group($value, $field, $at),
            'repeater' => $this->repeater($value, $field, $at),
            'block' => $this->child($value, $at),
            'blocks' => $this->children($value, $field, $at),
            default => $this->pick_($value, $field, $at),
        };
    }

    private function fail(string $at, string $message): null
    {
        $this->errors[$at] = $message;

        return null;
    }

    private function text(mixed $value, BlockField $field, string $at): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value)) {
            return $this->fail($at, __('Enter text.'));
        }

        $value = trim((string) $value);
        $max = in_array($field->type(), ['rich_text', 'textarea'], true) ? self::MAX_LONG_TEXT : self::MAX_TEXT;

        if (mb_strlen($value) > $max) {
            return $this->fail($at, __('Use at most :max characters.', ['max' => $max]));
        }

        return $value === '' ? null : $value;
    }

    private function number(mixed $value, BlockField $field, string $at): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return $this->fail($at, __('Enter a number.'));
        }

        $number = str_contains((string) $value, '.') ? (float) $value : (int) $value;
        $min = $field->get('min');
        $max = $field->get('max');

        if (($min !== null && $number < $min) || ($max !== null && $number > $max)) {
            return $this->fail($at, $max === null ? __('Enter at least :min.', ['min' => $min]) : __('Enter a number from :min to :max.', ['min' => $min ?? 0, 'max' => $max]));
        }

        return $number;
    }

    private function choice(mixed $value, BlockField $field, string $at): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value) || ! array_key_exists((string) $value, (array) $field->get('options', []))) {
            return $this->fail($at, __('Choose one of the options.'));
        }

        return (string) $value;
    }

    /**
     * @return array<int, string>|null
     */
    private function choices(mixed $value, BlockField $field, string $at): ?array
    {
        $values = array_values(array_filter((array) $value, 'is_string'));

        if (array_diff($values, array_map('strval', array_keys((array) $field->get('options', [])))) !== []) {
            return $this->fail($at, __('Choose from the options.'));
        }

        return $values;
    }

    private function color(mixed $value, string $at): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^(#[0-9a-f]{3,8}|var\(--color-[a-z0-9-]+\))$/i', $value) !== 1) {
            return $this->fail($at, __('Pick a colour.'));
        }

        return $value;
    }

    private function url(mixed $value, string $at, bool $anchors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = is_string($value) ? trim($value) : '';
        $valid = preg_match('#^https?://[^\s]+$#i', $value) === 1 || preg_match('#^/(?!/)[^\s]*$#', $value) === 1 || ($anchors && preg_match('/^#[A-Za-z0-9_-]+$/', $value) === 1);

        if (! $valid || mb_strlen($value) > 2048) {
            return $this->fail($at, $anchors ? __('Enter a page path starting with /, a full https:// address or a #section.') : __('Upload an image or enter a full https:// address.'));
        }

        return $value;
    }

    /**
     * @return array<int, string>|null
     */
    private function tags(mixed $value, string $at): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $values = is_string($value) ? preg_split('/[\n,]/', $value) : (array) $value;
        $tags = array_values(array_unique(array_filter(array_map(fn ($tag): string => is_scalar($tag) ? trim((string) $tag) : '', $values), fn (string $tag): bool => $tag !== '')));

        if (count($tags) > 50 || collect($tags)->contains(fn (string $tag): bool => mb_strlen($tag) > 80)) {
            return $this->fail($at, __('Use at most 50 keywords of 80 characters.'));
        }

        return $tags;
    }

    /**
     * A picked record: a slug / code / id, or an ordered list of them.
     *
     * @return string|int|array<int, string|int>|null
     */
    private function pick_(mixed $value, BlockField $field, string $at): string|int|array|null
    {
        $integers = $field->type() === 'product_picker';

        if ($field->get('multiple')) {
            if ($value === null || $value === '' || $value === []) {
                return null;
            }

            if (! is_array($value)) {
                return $this->fail($at, __('Pick from the list.'));
            }

            $picked = array_values(array_unique(array_filter(array_map(fn ($item) => is_scalar($item) ? ($integers ? (int) $item : trim((string) $item)) : null, $value), fn ($item): bool => $item !== null && $item !== '' && $item !== 0)));
            $max = $field->get('max');

            if ($max !== null && count($picked) > $max) {
                return $this->fail($at, __('Pick at most :max.', ['max' => $max]));
            }

            return $picked;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value) || mb_strlen((string) $value) > 190) {
            return $this->fail($at, __('Pick from the list.'));
        }

        return $integers ? (int) $value : trim((string) $value);
    }

    private function toLocal(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $moment = CarbonImmutable::parse($value);

            return ($this->country ? $moment->setTimezone($this->country->timezone) : $moment)->format('Y-m-d\TH:i');
        } catch (Throwable) {
            return $value;
        }
    }

    private function toUtc(mixed $value, string $at): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, $this->country?->timezone ?? 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return $this->fail($at, __('Enter a valid date and time.'));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function range(mixed $value, string $at): ?array
    {
        $range = is_array($value) ? $value : [];
        $range['starts_at'] = $this->toUtc($range['starts_at'] ?? null, "{$at}.starts_at");
        $range['ends_at'] = $this->toUtc($range['ends_at'] ?? null, "{$at}.ends_at");

        if ($range['starts_at'] !== null && $range['ends_at'] !== null && $range['ends_at'] <= $range['starts_at']) {
            $this->fail("{$at}.ends_at", __('The end must be after the start.'));
        }

        $range = array_filter($range, fn ($item): bool => $item !== null && $item !== '' && $item !== []);

        return $range === [] ? null : $range;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function group(mixed $value, BlockField $field, string $at): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $stored = $this->storedValues($field->fields(), $value, $at);

        return array_filter($stored, fn ($item): bool => $item !== null && $item !== '' && $item !== []) === [] ? null : $stored;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function repeater(mixed $value, BlockField $field, string $at): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $max = $field->get('max');

        if ($max !== null && count($value) > $max) {
            return $this->fail($at, __('Add at most :max items.', ['max' => $max]));
        }

        $items = [];

        foreach (array_values($value) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $stored = $this->storedValues($field->fields(), $item, "{$at}.{$index}");

            if ($stored !== []) {
                $items[] = $stored;
            }
        }

        return $items;
    }

    /**
     * A child block of a container: content blocks only.
     *
     * @return array<string, mixed>|null
     */
    private function child(mixed $value, string $at): ?array
    {
        if (! is_array($value) || ($value['type'] ?? '') === '') {
            return null;
        }

        $type = (string) $value['type'];

        if (! BlockRegistry::has($type) || in_array(BlockRegistry::get($type)['kind'], ['header', 'listing', 'container'], true)) {
            return $this->fail("{$at}.type", __('A container holds only content blocks.'));
        }

        $child = [...$value, 'type' => $type, 'settings' => $this->fromEditor($type, is_array($value['settings'] ?? null) ? $value['settings'] : [], "{$at}.settings")];

        if (empty($child['hidden'])) {
            unset($child['hidden']);
        } else {
            $child['hidden'] = true;
        }

        return $child;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function children(mixed $value, BlockField $field, string $at): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $max = $field->get('max');

        if ($max !== null && count($value) > $max) {
            return $this->fail($at, __('Add at most :max blocks.', ['max' => $max]));
        }

        $children = [];

        foreach (array_values($value) as $index => $child) {
            $stored = $this->child($child, "{$at}.{$index}");

            if ($stored !== null) {
                $children[] = $stored;
            }
        }

        return $children;
    }
}
