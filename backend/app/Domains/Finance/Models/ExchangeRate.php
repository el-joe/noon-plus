<?php

namespace App\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One currency rate (central): 1 `base_currency` = `rate` `quote_currency`
 * from `effective_at` until a newer row for the pair takes over. Rows are
 * added, never edited (a rate used on a payment is copied onto it), and
 * every row is in the activity log ("exchange_rates").
 *
 * @property string $rate
 */
#[Fillable(['base_currency', 'quote_currency', 'rate', 'effective_at', 'source', 'note', 'created_by'])]
class ExchangeRate extends Model
{
    use LogsActivity;

    public const SOURCE_MANUAL = 'manual';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['effective_at' => 'immutable_datetime'];
    }

    /**
     * @param  Builder<ExchangeRate>  $query
     */
    public function scopePair(Builder $query, string $base, string $quote): void
    {
        $query->where('base_currency', strtoupper($base))->where('quote_currency', strtoupper($quote));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('exchange_rates')
            ->logOnly(['base_currency', 'quote_currency', 'rate', 'effective_at', 'source', 'note'])
            ->dontSubmitEmptyLogs();
    }
}
