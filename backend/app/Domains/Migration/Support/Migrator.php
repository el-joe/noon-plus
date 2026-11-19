<?php

namespace App\Domains\Migration\Support;

use App\Domains\Migration\Steps\CartsStep;
use App\Domains\Migration\Steps\CatalogStep;
use App\Domains\Migration\Steps\ClassifiedsStep;
use App\Domains\Migration\Steps\ContentStep;
use App\Domains\Migration\Steps\ContractsStep;
use App\Domains\Migration\Steps\DeliveryStep;
use App\Domains\Migration\Steps\FilesStep;
use App\Domains\Migration\Steps\FinanceStep;
use App\Domains\Migration\Steps\IdentityStep;
use App\Domains\Migration\Steps\InventoryStep;
use App\Domains\Migration\Steps\ListingsStep;
use App\Domains\Migration\Steps\MarketersStep;
use App\Domains\Migration\Steps\MarketingStep;
use App\Domains\Migration\Steps\OrdersStep;
use App\Domains\Migration\Steps\PaymentsStep;
use App\Domains\Migration\Steps\ReferenceStep;
use App\Domains\Migration\Steps\SimpleStep;
use App\Domains\Migration\Steps\Step;
use App\Domains\Migration\Steps\SupportStep;
use App\Domains\Migration\Steps\TravelStep;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Runs legacy migration steps in pipeline order (plan §3) with one set of
 * run services (id map, writer, quarantine, archiver, resolver) and records
 * each step's metrics in `migration_runs`.
 */
class Migrator
{
    /**
     * @var array<string, class-string<Step>>
     */
    public const STEP_CLASSES = [
        'reference' => ReferenceStep::class,
        'identity' => IdentityStep::class,
        'catalog' => CatalogStep::class,
        'listings' => ListingsStep::class,
        'inventory' => InventoryStep::class,
        'carts' => CartsStep::class,
        'orders' => OrdersStep::class,
        'payments' => PaymentsStep::class,
        'finance' => FinanceStep::class,
        'marketing' => MarketingStep::class,
        'marketers' => MarketersStep::class,
        'contracts' => ContractsStep::class,
        'classifieds' => ClassifiedsStep::class,
        'travel' => TravelStep::class,
        'delivery' => DeliveryStep::class,
        'support' => SupportStep::class,
        'content' => ContentStep::class,
        'files' => FilesStep::class,
    ];

    private IdMap $ids;

    private TargetWriter $writer;

    private Quarantine $quarantine;

    private Archiver $archiver;

    private CountryResolver $countries;

    public function __construct(public readonly MigrationContext $context, private LegacySource $source)
    {
        $this->ids = new IdMap;
        $this->writer = new TargetWriter($context, $this->ids);
        $this->quarantine = new Quarantine($context);
        $this->archiver = new Archiver($context, $this->ids);
        $this->countries = new CountryResolver($source);
    }

    /**
     * @return array<int, string>
     */
    public static function resolveSteps(string $step): array
    {
        if ($step === 'all') {
            return TableRules::STEPS;
        }

        if (! in_array($step, TableRules::STEPS, true)) {
            throw new InvalidArgumentException("Unknown step {$step}. Steps: all, ".implode(', ', TableRules::STEPS));
        }

        return [$step];
    }

    /**
     * @return array<string, array<string, mixed>> metrics per table
     */
    public function run(string $step): array
    {
        $class = self::STEP_CLASSES[$step] ?? SimpleStep::class;
        $instance = new $class($this->context, $this->source, $this->ids, $this->writer, $this->quarantine, $this->archiver, $this->countries);

        if ($instance instanceof SimpleStep) {
            $instance->named($step);
        }

        $startedAt = now();
        $instance->run();
        $metrics = $this->context->metricsFor($step);

        if (! $this->context->dryRun) {
            DB::table('migration_runs')->insert([
                'run_id' => $this->context->runId,
                'step' => $step,
                'countries' => $this->context->countries === [] ? null : implode(',', $this->context->countries),
                'scope' => $this->context->scope(),
                'dry_run' => false,
                'metrics' => json_encode($metrics, JSON_UNESCAPED_UNICODE),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);
        }

        return $metrics;
    }
}
