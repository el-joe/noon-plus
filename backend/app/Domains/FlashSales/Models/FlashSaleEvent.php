<?php

namespace App\Domains\FlashSales\Models;

use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\MarketerBonusType;
use App\Domains\FlashSales\Support\FlashSaleEventMachine;
use App\Shared\Database\UsesCountryDatabase;
use Carbon\CarbonImmutable;
use Database\Factories\FlashSaleEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A flash-sale event in one country. Times are UTC (entered in the country's
 * local time). Status changes only through {@see FlashSaleEventMachine}.
 *
 * @property FlashSaleEventStatus $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
#[UseFactory(FlashSaleEventFactory::class)]
#[Fillable([
    'name', 'display_name', 'slug', 'submission_starts_at', 'submission_ends_at', 'starts_at', 'ends_at',
    'max_qty_per_customer', 'marketer_bonus_type', 'marketer_bonus_value', 'created_by',
])]
class FlashSaleEvent extends Model
{
    /** @use HasFactory<FlashSaleEventFactory> */
    use HasFactory, UsesCountryDatabase;

    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'draft'];

    /**
     * Every event gets a public slug (G-W30): "{name}-{id}", set once.
     */
    protected static function booted(): void
    {
        static::created(function (FlashSaleEvent $event): void {
            if ($event->slug === null) {
                $event->forceFill(['slug' => (Str::slug((string) ($event->display_name ?: $event->name)) ?: 'flash-sale').'-'.$event->id])->saveQuietly();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FlashSaleEventStatus::class,
            'marketer_bonus_type' => MarketerBonusType::class,
            'marketer_bonus_value' => 'decimal:4',
            'submission_starts_at' => 'immutable_datetime',
            'submission_ends_at' => 'immutable_datetime',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<FlashSaleSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FlashSaleSubmission::class);
    }

    /**
     * @return HasMany<FlashSaleEventStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(FlashSaleEventStatusChange::class)->orderBy('id');
    }

    /**
     * When sellers can no longer submit: the submission end, else the start.
     */
    public function submissionDeadline(): CarbonImmutable
    {
        return $this->submission_ends_at ?? $this->starts_at;
    }
}
