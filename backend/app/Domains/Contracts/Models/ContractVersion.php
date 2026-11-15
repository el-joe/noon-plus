<?php

namespace App\Domains\Contracts\Models;

use App\Domains\Contracts\Enums\ContractVersionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One version of a template (central). A published version is never edited;
 * editing creates the next draft. Files live on the private disk.
 *
 * @property ContractVersionStatus $status
 */
#[Fillable(['contract_template_id', 'version', 'status', 'body_en', 'body_ar', 'file_path_ar', 'file_path_en', 'file_mime', 'change_summary', 'effective_at', 'published_at', 'retired_at', 'published_by'])]
class ContractVersion extends Model
{
    use LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContractVersionStatus::class,
            'effective_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ContractTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }

    public function isFile(): bool
    {
        return $this->file_path_ar !== null || $this->file_path_en !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['version', 'status', 'effective_at', 'change_summary'])->logOnlyDirty()->useLogName('contracts');
    }
}
