<?php

namespace App\Domains\Reporting\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Reporting\Models\EtlRun;
use App\Domains\Reporting\Support\ReportingEtl;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('nawy:reporting:etl {--date= : One local date (Y-m-d)} {--from= : Backfill start (Y-m-d)} {--to= : Backfill end (Y-m-d, default = --from)} {--country=* : Only these country codes}')]
#[Description('Load the reporting database from every country database (nightly; default = yesterday in each country)')]
class RunReportingEtlCommand extends Command
{
    public function handle(ReportingEtl $etl): int
    {
        $from = $this->option('date') ?? $this->option('from');
        $to = $this->option('date') ?? $this->option('to') ?? $from;

        foreach (array_filter([$from, $to]) as $date) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                $this->components->error("Invalid date {$date}");

                return self::INVALID;
            }
        }

        if ($from !== null && $to < $from) {
            $this->components->error('--to is before --from');

            return self::INVALID;
        }

        $batch = (string) Str::uuid();
        $failed = false;

        Country::query()->active()
            ->when($this->option('country'), fn ($query, array $codes) => $query->whereIn('code', $codes))
            ->orderBy('id')
            ->each(function (Country $country) use ($etl, $from, $to, $batch, &$failed): void {
                $start = $from === null ? $etl->yesterday($country) : CarbonImmutable::parse($from);
                $end = $to === null ? $start : CarbonImmutable::parse($to);
                $run = $etl->load($country, $start, $end, $from === null ? 'schedule' : 'backfill', batch: $batch);
                $failed = $failed || $run->status === EtlRun::FAILED;

                $run->status === EtlRun::FAILED
                    ? $this->components->error("{$country->code}: failed — {$run->error}")
                    : $this->components->info("{$country->code}: {$run->rows_written} rows ({$start->toDateString()} → {$end->toDateString()})");
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
