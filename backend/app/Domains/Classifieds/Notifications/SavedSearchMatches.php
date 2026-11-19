<?php

namespace App\Domains\Classifieds\Notifications;

use App\Shared\Notifications\Email\EventEmail;

/**
 * Saved-search alert (`classified.saved_search`): new live ads matching a customer's saved search.
 */
class SavedSearchMatches extends EventEmail
{
    /**
     * @param  array<int, string>  $titles
     */
    public function __construct(public string $searchName, public int $count, public array $titles, ?string $url = null)
    {
        parent::__construct('classified.saved_search', [
            'search' => $searchName,
            'count' => (string) $count,
        ], array_filter([
            'url' => $url,
            'blocks' => [['type' => 'table', 'columns' => ['Ad'], 'rows' => array_map(fn (string $title): array => [$title], array_slice($titles, 0, 5))]],
        ]));
    }
}
