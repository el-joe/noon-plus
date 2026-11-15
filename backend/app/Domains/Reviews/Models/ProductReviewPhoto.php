<?php

namespace App\Domains\Reviews\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A photo attached to a review (customer upload, public disk).
 */
#[Fillable(['product_review_id', 'path', 'sort_order'])]
class ProductReviewPhoto extends Model
{
    use UsesCountryDatabase;

    public const DISK = 'public';

    public function url(): string
    {
        return Storage::disk(self::DISK)->url($this->path);
    }
}
