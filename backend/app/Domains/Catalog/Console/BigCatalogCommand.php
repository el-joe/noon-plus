<?php

namespace App\Domains\Catalog\Console;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Support\DevCatalog\BigCatalogBlueprint;
use App\Domains\Catalog\Support\DevCatalog\BigCatalogSeeder;
use App\Domains\Content\Enums\PageTemplate;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Support\DevLayouts\CategoryLayout;
use App\Domains\Content\Support\DevLayouts\HomeLayout;
use App\Domains\Content\Support\Pages;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('nawy:dev:big-catalog {--country=all : Country codes, comma separated, or all} {--products=5000 : Products per country} {--vendors=60 : Vendors per country} {--seed=20261119 : Deterministic seed} {--no-pages : Do not publish the home / category pages} {--no-post : Skip the search index rebuild and reporting ETL}')]
#[Description('Development only: seed a big, deterministic catalogue per country (vendors, products, variants, media, multi-seller offers, deals, coupons, ads) and publish the home and category pages')]
class BigCatalogCommand extends Command
{
    public function handle(BigCatalogSeeder $seeder, CountryContext $context, Pages $pages): int
    {
        if (app()->isProduction()) {
            $this->error('nawy:dev:big-catalog refuses to run in production.');

            return self::FAILURE;
        }

        $codes = $this->option('country') === 'all' ? null : array_filter(array_map('trim', explode(',', (string) $this->option('country'))));
        $countries = Country::query()->when($codes, fn ($query) => $query->whereIn('code', $codes))->orderBy('sort_order')->get();

        if ($countries->isEmpty()) {
            $this->error('No country matches --country.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($countries as $country) {
            $started = microtime(true);
            $this->info("Seeding {$country->code}…");
            $totals = $seeder->seed($country, max(1, (int) $this->option('products')), max(1, (int) $this->option('vendors')), (int) $this->option('seed'), fn (string $line) => $this->line($line));
            $published = $this->option('no-pages') ? 0 : $this->publishPages($country, $context, $pages);
            $rows[] = [$country->code, $totals['products'], $totals['variants'], $totals['offers'], $totals['vendors'], $totals['media'], $published, round(microtime(true) - $started, 1).' s'];
        }

        if (! $this->option('no-post')) {
            $this->call('nawy:search:rebuild-index');
            $this->call('nawy:reporting:etl', ['--date' => now()->toDateString(), '--country' => $countries->pluck('code')->all()]);
        }

        $this->table(['Country', 'Products', 'Variants', 'Offers', 'Vendors', 'Media', 'Pages', 'Time'], $rows);

        return self::SUCCESS;
    }

    /** Home (en, ar) and one category page per root category (en, ar), published. */
    private function publishPages(Country $country, CountryContext $context, Pages $pages): int
    {
        $userId = (int) DB::table('role_memberships')->where('role', 'admin')->orderBy('user_id')->value('user_id');

        return $context->runAs($country, function (Country $country) use ($pages, $userId): int {
            $published = 0;

            foreach (['en', 'ar'] as $locale) {
                $published += $this->publish($pages, PageTemplate::Home, 'home', $locale, $locale === 'ar' ? 'الرئيسية' : 'Home', app(HomeLayout::class)->zones($country, $locale), $userId);

                foreach (array_keys(BigCatalogBlueprint::tree()) as $slug) {
                    $root = Category::query()->where('slug', $slug)->first();

                    // The `home` root shares its address with the home page: it keeps the default category layout.
                    if ($root !== null && $slug !== 'home') {
                        $published += $this->publish($pages, PageTemplate::Category, $slug, $locale, $locale === 'ar' ? $root->name_ar : $root->name_en, app(CategoryLayout::class)->zones($country, $root, $locale), $userId);
                    }
                }
            }

            return $published;
        });
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $zones
     */
    private function publish(Pages $pages, PageTemplate $template, string $slug, string $locale, string $title, array $zones, int $userId): int
    {
        try {
            $page = Page::query()->where('slug', $slug)->where('locale', $locale)->first()
                ?? $pages->create(['template' => $template->value, 'slug' => $slug, 'locale' => $locale, 'title' => $title], $userId);

            if ($page->template !== $template) {
                $this->warn("  page {$slug} ({$locale}) exists with another template; left as it is");

                return 0;
            }

            $pages->saveDraft($page, $zones, $userId, 'nawy:dev:big-catalog');
            $pages->publish($page->refresh(), ActorType::Admin, $userId);

            return 1;
        } catch (Throwable $exception) {
            $this->warn("  page {$slug} ({$locale}) not published: {$exception->getMessage()}");

            return 0;
        }
    }
}
