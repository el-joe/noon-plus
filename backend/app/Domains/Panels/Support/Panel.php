<?php

namespace App\Domains\Panels\Support;

use App\Domains\Identity\Enums\MembershipRole;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * A business panel defined in config('nawy.panels').
 */
final readonly class Panel
{
    /**
     * @param  array<int, MembershipRole>  $roles
     */
    public function __construct(
        public string $key,
        public string $host,
        public string $name,
        public array $roles,
    ) {}

    public static function find(string $key): self
    {
        $definition = config("nawy.panels.{$key}");

        abort_if($definition === null, 404);

        return new self(
            $key,
            $definition['host'].'.'.config('nawy.base_domain'),
            $definition['name'],
            array_map(fn (string $role): MembershipRole => MembershipRole::from($role), $definition['roles']),
        );
    }

    /**
     * The panel serving the current request host, if any.
     */
    public static function fromRequest(Request $request): ?self
    {
        foreach (array_keys(config('nawy.panels')) as $key) {
            $panel = self::find($key);

            if ($panel->host === $request->getHost()) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return array_map(fn (string $key): self => self::find($key), array_keys(config('nawy.panels')));
    }

    public function allows(User $user): bool
    {
        foreach ($this->roles as $role) {
            if ($user->hasMembership($role)) {
                return true;
            }
        }

        return false;
    }

    public function route(string $name): string
    {
        return $this->key.'.'.$name;
    }
}
