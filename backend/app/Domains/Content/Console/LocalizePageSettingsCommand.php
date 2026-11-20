<?php

namespace App\Domains\Content\Console;

use App\Domains\Content\Enums\PageStatus;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Support\PageLocalizer;
use App\Domains\Content\Support\Pages;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One-off move of page-builder layouts to bilingual settings
 * ({@see PageLocalizer}): per country and address, the EN and AR pages'
 * current drafts are merged into one bilingual layout saved as a new draft
 * revision of every language page; pages whose draft was the live version
 * are republished so the storefront keeps showing the same content.
 * Pages with unpublished draft changes keep their live revision (it still
 * renders: plain values are read as both languages). Idempotent.
 */
#[Signature('nawy:pages:localize-settings {--dry-run : Report what would change without writing} {--country= : Only this country code}')]
#[Description('Convert page-builder block settings to bilingual {en, ar} values and link the EN/AR pages of each address')]
class LocalizePageSettingsCommand extends Command
{
    public function handle(CountryContext $countryContext, Pages $pages, PageLocalizer $localizer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $totals = ['addresses' => 0, 'pages' => 0, 'unchanged' => 0, 'republished' => 0, 'split' => 0];

        foreach (Country::query()->active()->when($this->option('country'), fn ($query, $code) => $query->where('code', $code))->get() as $country) {
            $countryContext->runAs($country, function () use ($country, $pages, $localizer, $dryRun, &$totals): void {
                foreach (Page::query()->with(['currentRevision'])->orderBy('slug')->get()->groupBy(fn (Page $page): string => $page->slug.'|'.$page->template->value) as $group) {
                    $group = $group->filter(fn (Page $page): bool => $page->currentRevision !== null)->values();

                    if ($group->isEmpty()) {
                        continue;
                    }

                    $english = $group->firstWhere('locale', 'en');
                    $arabic = $group->firstWhere('locale', 'ar');

                    // Already linked by an earlier run: from then on the editor keeps both languages in step, so a
                    // difference between them is an edit in progress. Merging again would turn a section added in one
                    // language into a language-limited one (the Oman home hero bug); only convert each page itself.
                    $linked = $english && $arabic && $group->contains(fn (Page $page): bool => $page->revisions()->where('note', __('Bilingual settings (EN + AR)'))->exists());

                    if ($english && $arabic && ! $linked) {
                        $merged = $localizer->merge($english->currentRevision->zones, $arabic->currentRevision->zones);
                        $zones = $merged['zones'];
                        $totals['split'] += $merged['split'];
                    } else {
                        $only = $group->first();
                        $zones = $localizer->convert($only->currentRevision->zones, $only->locale);
                    }

                    $totals['addresses']++;

                    foreach ($group as $page) {
                        $normalized = $pages->normalize($page->template, $linked ? $localizer->convert($page->currentRevision->zones, $page->locale) : $zones);

                        // MySQL JSON columns reorder object keys: compare key-order-insensitively.
                        if ($this->canonical($normalized) === $this->canonical($pages->normalize($page->template, $page->currentRevision->zones))) {
                            $totals['unchanged']++;

                            continue;
                        }

                        $live = $page->status === PageStatus::Published && $page->current_revision_id === $page->published_revision_id;
                        $totals['pages']++;
                        $this->line(sprintf('%s %s/%s (%s)%s', $dryRun ? '[dry-run]' : 'converted', $country->code, $page->slug, $page->locale, $live ? ' · republished' : ''));

                        if ($dryRun) {
                            $totals['republished'] += $live ? 1 : 0;

                            continue;
                        }

                        $pages->saveDraft($page, $normalized, (int) ($page->updated_by ?? 0) ?: 0, __('Bilingual settings (EN + AR)'));

                        if ($live) {
                            $pages->publish($page->refresh(), ActorType::System, null);
                            $totals['republished']++;
                        }
                    }
                }
            });
        }

        $this->components->info(sprintf('%s: %d addresses, %d pages converted (%d republished), %d already bilingual, %d blocks kept per language.', $dryRun ? 'Dry run' : 'Done', $totals['addresses'], $totals['pages'], $totals['republished'], $totals['unchanged'], $totals['split']));

        return self::SUCCESS;
    }

    private function canonical(mixed $value): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return (string) json_encode($sort($value));
    }
}
