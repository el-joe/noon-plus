<?php

namespace App\Domains\Content\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One "was this helpful?" answer on a help article (G-W25): one per voter
 * (signed-in user, else visitor token, else IP — hashed in `voter_key`);
 * voting again changes the answer.
 */
#[Fillable(['help_article_id', 'voter_key', 'user_id', 'is_helpful'])]
class HelpArticleVote extends Model
{
    use UsesCountryDatabase;

    protected $table = 'help_article_votes';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_helpful' => 'boolean'];
    }
}
