<?php

namespace App\Domains\Content\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One saved version of a page's zones and blocks. Append-only (SRS §15):
 * revisions are never changed or deleted; restoring copies an old one into
 * a new revision.
 */
#[Fillable(['page_id', 'number', 'zones', 'note', 'created_by'])]
class PageRevision extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Page revisions are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Page revisions are append-only.'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['zones' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
