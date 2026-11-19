<?php

namespace App\Domains\Migration\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The 301 map from the old platform's URLs to the new website (plan §7).
 * Only URLs that change are listed (old category, product and static-page
 * URLs keep their path because slugs are kept); a migrated row whose slug
 * had to change ("-legacy", or a category slug normalised to `[a-z0-9-]`)
 * gets its own entry. Sources:
 * - storefront `/{cc}-{lang}/…` (same market segment as the new website):
 *   seller pages by id → store slug, marketer pages → creators, account
 *   pages → /account/…, old orders by id → order number, categories and
 *   products whose slug changed, live streams;
 * - seller portal `/{cc}/helpcenter|adsupport/…` and the un-prefixed
 *   variants, `/blog/{slug}` → the new help centre, ads knowledge hub and blog.
 * Stored in `legacy_redirects` and exported as JSON and an nginx map
 * (local disk, storage/app/private/migration/) for the proxy.
 */
class RedirectMap
{
    /**
     * Old storefront account / misc pages → new path (after the market segment).
     *
     * @var array<string, string>
     */
    public const STATIC_STOREFRONT = [
        'orders' => 'account/orders', 'my-bookings' => 'account/bookings', 'addresses' => 'account/addresses',
        'wishlist' => 'account/wishlist', 'notifications' => 'account/notifications', 'profile' => 'account/profile',
        'returns' => 'account/returns', 'disputes' => 'account/disputes', 'tickets' => 'account/support',
        'noon-credits' => 'account/credits', 'my-contracts' => 'account/contracts', 'my-warranties' => 'account/warranty',
        'warranty-claims' => 'account/warranty', 'special-requests' => 'account/special-requests',
        'security-settings' => 'account/security', 'payments' => 'account', 'qr-code' => 'account', 'chats' => 'account/inquiries',
        'live-streams' => 'live', 'nawy-options' => 'join',
    ];

    /**
     * @var array<string, array{to: string, source: string, country: string|null}>
     */
    private array $entries = [];

    public function __construct(private LegacySource $source, private IdMap $ids = new IdMap) {}

    /**
     * @return array<string, array{to: string, source: string, country: string|null}>
     */
    public function build(): array
    {
        $this->entries = [];

        foreach (MigrationContext::allCountries() as $code) {
            foreach (['en', 'ar'] as $locale) {
                $market = "/{$code}-{$locale}";

                foreach (self::STATIC_STOREFRONT as $old => $new) {
                    $this->add("{$market}/{$old}", "{$market}/{$new}", 'storefront', $code);
                }

                $this->vendors($code, $market);
                $this->marketers($code, $market);
                $this->orders($code, $market);
                $this->changedSlugs($code, $market, 'categories', 'categories', 'categories', '');
                $this->changedSlugs($code, $market, 'products', 'products', 'products', 'products/');
                $this->changedSlugs($code, $market, 'blog_posts', 'blog_posts', 'blog_posts', 'blog/');
            }

            $this->portal($code);
        }

        ksort($this->entries);

        return $this->entries;
    }

    /**
     * Replace the stored map with a fresh build and export it.
     */
    public function store(): int
    {
        $entries = $this->build();

        DB::transaction(function () use ($entries): void {
            DB::table('legacy_redirects')->delete();

            foreach (array_chunk(array_map(fn (string $from, array $entry): array => [
                'from_path' => $from,
                'to_path' => $entry['to'],
                'status' => 301,
                'source' => $entry['source'],
                'country_code' => $entry['country'],
                'created_at' => now(),
                'updated_at' => now(),
            ], array_keys($entries), $entries), 500) as $chunk) {
                DB::table('legacy_redirects')->insert($chunk);
            }
        });

        $export = (string) config('nawy.legacy.redirects_export');
        Storage::disk('local')->put($export, json_encode(array_map(fn (array $entry): string => $entry['to'], $entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Storage::disk('local')->put(preg_replace('/\.json$/', '.map', $export) ?? $export.'.map', implode("\n", array_map(
            fn (string $from, array $entry): string => '"'.addcslashes($from, '"\\').'" "'.addcslashes($entry['to'], '"\\').'";',
            array_keys($entries),
            $entries,
        ))."\n");

        LegacyRedirects::flush();

        return count($entries);
    }

    private function add(string $from, string $to, string $source, ?string $country): void
    {
        if ($from === $to || isset($this->entries[$from])) {
            return;
        }

        $this->entries[$from] = ['to' => $to, 'source' => $source, 'country' => $country];
    }

    private function vendors(string $code, string $market): void
    {
        foreach (['vendors', 'travel_agencies'] as $table) {
            foreach (DB::table('migration_id_map')->where('source_table', $table)->where('target_db', $code)->where('target_table', 'vendors')->get() as $entry) {
                $slug = MigrationContext::db($code)->table('vendors')->where('id', $entry->target_id)->value('slug');
                $legacy = $this->source->find($table, $entry->source_id);

                if ($slug === null) {
                    continue;
                }

                $this->add("{$market}/seller/{$entry->source_id}", "{$market}/seller/{$slug}", 'storefront', $code);

                if ($table === 'vendors' && ($legacy['store_slug'] ?? null) && $legacy['store_slug'] !== $slug) {
                    $this->add("{$market}/seller/{$legacy['store_slug']}", "{$market}/seller/{$slug}", 'storefront', $code);
                }
            }
        }
    }

    private function marketers(string $code, string $market): void
    {
        foreach (DB::table('migration_id_map')->where('source_table', 'marketers')->where('target_db', $code)->where('target_table', 'marketer_profiles')->get() as $entry) {
            $slug = MigrationContext::db($code)->table('marketer_profiles')->where('id', $entry->target_id)->value('slug');
            $legacy = $this->source->connection()->table('marketer_profiles')->where('marketer_id', $entry->source_id)->value('profile_slug');

            if ($slug !== null) {
                $this->add("{$market}/marketer/".($legacy ?: $slug), "{$market}/creators/{$slug}", 'storefront', $code);
            }
        }
    }

    private function orders(string $code, string $market): void
    {
        foreach (DB::table('migration_id_map')->where('source_table', 'orders')->where('target_db', $code)->where('target_table', 'orders')->get() as $entry) {
            $number = MigrationContext::db($code)->table('orders')->where('id', $entry->target_id)->value('number');

            if ($number !== null) {
                $this->add("{$market}/orders/{$entry->source_id}", "{$market}/account/orders/{$number}", 'storefront', $code);
            }
        }
    }

    /**
     * Rows whose slug had to change on the way in.
     */
    private function changedSlugs(string $code, string $market, string $sourceTable, string $legacyTable, string $targetTable, string $prefix): void
    {
        foreach (DB::table('migration_id_map')->where('source_table', $sourceTable)->where('target_db', $code)->where('target_table', $targetTable)->where('origin', 'inserted')->get() as $entry) {
            $legacySlug = $this->source->find($legacyTable, $entry->source_id)['slug'] ?? null;
            $slug = MigrationContext::db($code)->table($targetTable)->where('id', $entry->target_id)->value('slug');

            if ($legacySlug !== null && $slug !== null && $legacySlug !== $slug) {
                $this->add("{$market}/{$prefix}{$legacySlug}", "{$market}/{$prefix}{$slug}", 'storefront', $code);
            }
        }
    }

    /**
     * The old seller portal (`/{cc}/…` and un-prefixed paths) → the new website in English.
     */
    private function portal(string $code): void
    {
        $market = "/{$code}-en";
        $default = $code === 'uae';
        $prefixes = $default ? ["/{$code}", ''] : ["/{$code}"];

        foreach ($prefixes as $prefix) {
            $this->add("{$prefix}/helpcenter", "{$market}/help", 'portal', $code);
            $this->add("{$prefix}/adsupport", "{$market}/adsupport", 'portal', $code);

            foreach (['help_center_categories' => 'helpcenter/category/', 'ad_support_collections' => 'adsupport/collections/'] as $table => $old) {
                $new = $table === 'help_center_categories' ? 'help/collections/' : 'adsupport/collections/';

                foreach (DB::table('migration_id_map')->where('source_table', $table)->where('target_db', $code)->where('target_table', 'help_collections')->get() as $entry) {
                    $legacy = $this->source->find($table, $entry->source_id);
                    $slug = MigrationContext::db($code)->table('help_collections')->where('id', $entry->target_id)->value('slug');

                    if ($legacy !== null && $slug !== null) {
                        $this->add("{$prefix}/{$old}{$legacy['slug']}", "{$market}/{$new}{$slug}", 'portal', $code);
                    }
                }
            }

            foreach (['help_center_articles' => ['helpcenter/article/', 'help/articles/'], 'ad_support_articles' => ['adsupport/articles/', 'adsupport/articles/']] as $table => [$old, $new]) {
                foreach (DB::table('migration_id_map')->where('source_table', $table)->where('target_db', $code)->where('target_table', 'help_articles')->get() as $entry) {
                    $legacy = $this->source->find($table, $entry->source_id);
                    $slug = MigrationContext::db($code)->table('help_articles')->where('id', $entry->target_id)->value('slug');

                    if ($legacy !== null && $slug !== null) {
                        $this->add("{$prefix}/{$old}{$legacy['slug']}", "{$market}/{$new}{$slug}", 'portal', $code);
                    }
                }
            }
        }

        if ($default) {
            $this->add('/blog', "{$market}/blog", 'portal', $code);

            foreach (DB::table('migration_id_map')->where('source_table', 'blog_posts')->where('target_db', $code)->where('target_table', 'blog_posts')->get() as $entry) {
                $legacy = $this->source->find('blog_posts', $entry->source_id);
                $slug = MigrationContext::db($code)->table('blog_posts')->where('id', $entry->target_id)->value('slug');

                if ($legacy !== null && $slug !== null) {
                    $this->add("/blog/{$legacy['slug']}", "{$market}/blog/{$slug}", 'portal', $code);
                }
            }

            foreach (['/join' => '/join', '/vendors' => '/sell', '/marketers' => '/marketers', '/companies/travel-agency' => '/sell/travel-agency', '/faq' => '/help'] as $old => $new) {
                $this->add($old, $market.$new, 'portal', $code);
            }
        }
    }
}
