<?php

namespace App\Shared\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Staff and customer names for actor columns (audit trails, histories,
 * "Prepared by", "By"): panels show the person, not "Admin #37"
 * (browser test BT-ADM-007). Names are fetched in one query per page and
 * remembered for the request.
 */
final class UserNames
{
    /** @var array<int, string|null> */
    private array $names = [];

    /**
     * Load the names of these user ids in one query.
     *
     * @param  iterable<int|string|null>  $userIds
     */
    public function preload(iterable $userIds): self
    {
        $missing = collect($userIds)->filter()->map(fn ($id): int => (int) $id)->unique()
            ->reject(fn (int $id): bool => array_key_exists($id, $this->names))->values();

        if ($missing->isNotEmpty()) {
            $found = User::query()->whereIn('id', $missing)->pluck('name', 'id');
            $missing->each(function (int $id) use ($found): void {
                $this->names[$id] = $found->get($id);
            });
        }

        return $this;
    }

    /**
     * The user's name, or "User #id" when the account no longer exists.
     */
    public function name(int|string|null $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $this->preload([$userId]);

        return $this->names[(int) $userId] ?? __('User #:id', ['id' => $userId]);
    }

    /**
     * @param  iterable<int|string|null>  $userIds
     * @return Collection<int, string|null>
     */
    public function many(iterable $userIds): Collection
    {
        $this->preload($userIds);

        return collect($userIds)->filter()->unique()->mapWithKeys(fn ($id): array => [(int) $id => $this->name($id)]);
    }
}
