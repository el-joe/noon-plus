<?php

namespace App\Domains\Reporting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One country's part of a reporting ETL run (FR-CTY-10): the local dates
 * loaded, how it was started, rows written, duration and any error. Lives in
 * the reporting database.
 */
#[Fillable(['batch', 'country_code', 'date_from', 'date_to', 'status', 'trigger', 'triggered_by_user_id', 'rows_written', 'started_at', 'finished_at', 'duration_ms', 'error'])]
class EtlRun extends Model
{
    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $connection = 'reporting';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date_from' => 'immutable_date', 'date_to' => 'immutable_date', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
