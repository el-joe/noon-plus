<?php

namespace App\Domains\Migration\Support;

/**
 * The rule of every legacy table (data-migration-plan §4, file
 * `table-rules.php`): pipeline step, disposition and how its country is
 * resolved. Dispositions:
 * - `map`: a Step handler copies it field by field into new tables;
 * - `fold`: its data is carried by another table's handler (attributes into
 *   category_attributes, page blocks into page revisions…);
 * - `verify`: matched against the new configuration, not copied;
 * - `archive`: copied as it was into `legacy_records` (country or central);
 * - `drop`: transient (cache, sessions, tokens…, rule R8).
 */
class TableRules
{
    /**
     * The step pipeline, in dependency order (plan §3).
     *
     * @var array<int, string>
     */
    public const STEPS = [
        'reference', 'identity', 'catalog', 'listings', 'inventory', 'carts', 'orders', 'payments', 'finance',
        'marketing', 'marketers', 'contracts', 'classifieds', 'travel', 'delivery', 'support', 'content', 'streams', 'files',
    ];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $rules = null;

    /**
     * @return array<string, array{step: string, disposition: string, rule: string, scope: string, note: string, country_column?: string, via?: string, parent?: string, morph?: array<int, string>}>
     */
    public static function all(): array
    {
        return self::$rules ??= require __DIR__.'/table-rules.php';
    }

    /**
     * @return array{step: string, disposition: string, rule: string, scope: string, note: string, country_column?: string, via?: string, parent?: string, morph?: array<int, string>}|null
     */
    public static function for(string $table): ?array
    {
        return self::all()[$table] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function forStep(string $step): array
    {
        return array_filter(self::all(), fn (array $rule): bool => $rule['step'] === $step);
    }
}
