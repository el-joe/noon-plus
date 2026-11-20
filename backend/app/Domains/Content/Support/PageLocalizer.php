<?php

namespace App\Domains\Content\Support;

/**
 * Moves page-builder layouts to bilingual settings (`{en, ar}`).
 *
 * Pages stay one row per address and language (statuses, revisions, the
 * storefront API and copy-to-country are unchanged); the languages of one
 * address become *linked*: they share one bilingual layout that the editor
 * saves to all of them. To get there, the EN and AR layouts of an address
 * are merged block by block (matched by block id): texts take EN from the
 * English page and AR from the Arabic one; images and links keep the
 * English value and add the Arabic one only when it differs. Blocks that
 * exist in one language only, or whose shared settings (sources, counts,
 * layout…) differ between the languages, are kept as two blocks limited to
 * their language (`locales: ["en"]` / `["ar"]`), so nothing is lost and the
 * storefront renders exactly as before.
 */
class PageLocalizer
{
    /**
     * Marker of shared values that differ between the languages.
     */
    private const CONFLICT = "\0conflict";

    /**
     * One page's zones with every block's settings in the bilingual shape.
     *
     * @param  array<string, mixed>  $zones
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function convert(array $zones, string $locale): array
    {
        $converter = new BlockSettings($locale);

        return array_map(fn ($blocks) => array_map(fn ($block) => is_array($block)
            ? [...$block, 'settings' => $converter->bilingual((string) ($block['type'] ?? ''), (array) ($block['settings'] ?? []))]
            : $block, array_values((array) $blocks)), $zones);
    }

    /**
     * Merge the English and Arabic layouts of one address.
     *
     * @param  array<string, mixed>  $english
     * @param  array<string, mixed>  $arabic
     * @return array{zones: array<string, array<int, array<string, mixed>>>, split: int}
     */
    public function merge(array $english, array $arabic): array
    {
        $english = $this->convert($english, 'en');
        $arabic = $this->convert($arabic, 'ar');
        $zones = [];
        $split = 0;

        foreach (array_unique([...array_keys($english), ...array_keys($arabic)]) as $zone) {
            $en = array_values((array) ($english[$zone] ?? []));
            $ar = array_values((array) ($arabic[$zone] ?? []));
            $arById = collect($ar)->keyBy(fn ($block) => (string) ($block['id'] ?? ''))->all();
            $used = [];
            $out = [];

            foreach ($en as $block) {
                $id = (string) ($block['id'] ?? '');
                $twin = $id !== '' ? ($arById[$id] ?? null) : null;

                if ($twin !== null && ($twin['type'] ?? null) === ($block['type'] ?? null) && ! empty($twin['hidden']) === ! empty($block['hidden'])) {
                    $used[$id] = true;
                    $settings = $this->mergeValues($block['settings'] ?? [], $twin['settings'] ?? []);

                    if ($settings !== self::CONFLICT) {
                        $out[] = [...$block, 'settings' => $settings];

                        continue;
                    }

                    $split++;
                    $out[] = [...$block, 'locales' => ['en']];
                    $out[] = [...$twin, 'id' => $id.'-ar', 'locales' => ['ar']];

                    continue;
                }

                $out[] = [...$block, 'locales' => ['en']];
            }

            // Arabic-only blocks go after the block that precedes them in the Arabic layout.
            $previous = null;

            foreach ($ar as $block) {
                $id = (string) ($block['id'] ?? '');

                if (! isset($used[$id])) {
                    $position = $previous === null ? 0 : collect($out)->search(fn ($placed) => in_array((string) ($placed['id'] ?? ''), [$previous, $previous.'-ar'], true));
                    $position = $position === false ? count($out) : ($previous === null ? 0 : $position + 1);
                    array_splice($out, $position, 0, [[...$block, 'id' => $id !== '' && isset($arById[$id]) && collect($en)->contains(fn ($b) => ($b['id'] ?? null) === $id) ? $id.'-ar' : ($id ?: null), 'locales' => ['ar']]]);
                    $split++;
                }

                $previous = $id;
            }

            $zones[$zone] = $out;
        }

        return ['zones' => $zones, 'split' => $split];
    }

    /**
     * Merge two bilingual settings trees; {@see self::CONFLICT} when shared values differ.
     */
    private function mergeValues(mixed $en, mixed $ar): mixed
    {
        if (BlockSettings::isTranslation($en) || BlockSettings::isTranslation($ar)) {
            $enSide = BlockSettings::isTranslation($en) ? ($en['en'] ?? null) : $en;
            $arSide = BlockSettings::isTranslation($ar) ? ($ar['ar'] ?? $ar['en'] ?? null) : $ar;

            if ($enSide === null) {
                $arSide = BlockSettings::isTranslation($ar) ? ($ar['ar'] ?? null) : $ar;

                return $arSide === null ? null : ['ar' => $arSide];
            }

            // Media keep one value when both languages use the same one.
            $media = BlockSettings::isTranslation($ar) && ! isset($ar['ar']);

            return $arSide === null || ($media && $arSide === $enSide) ? ['en' => $enSide] : ['en' => $enSide, 'ar' => $arSide];
        }

        if (is_array($en) && is_array($ar)) {
            if (array_is_list($en) !== array_is_list($ar) || (array_is_list($en) && count($en) !== count($ar))) {
                return self::CONFLICT;
            }

            $merged = [];

            foreach (array_unique([...array_keys($en), ...array_keys($ar)]) as $key) {
                if (! array_key_exists($key, $en) || ! array_key_exists($key, $ar)) {
                    $value = $en[$key] ?? $ar[$key];

                    // A shared key set in one language only would change the other: keep them apart.
                    if (! BlockSettings::isTranslation($value)) {
                        return self::CONFLICT;
                    }

                    $merged[$key] = $this->mergeValues($en[$key] ?? null, $ar[$key] ?? null);

                    continue;
                }

                $value = $this->mergeValues($en[$key], $ar[$key]);

                if ($value === self::CONFLICT) {
                    return self::CONFLICT;
                }

                $merged[$key] = $value;
            }

            return $merged;
        }

        return $en === $ar ? $en : self::CONFLICT;
    }
}
