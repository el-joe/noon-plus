<?php

namespace App\Domains\Countries\Support;

use App\Domains\Countries\Exceptions\CountryContextMissing;
use App\Domains\Countries\Models\Country;
use Illuminate\Support\Facades\Context;

/**
 * Holds the country the current request, job or command is acting for.
 *
 * Country-scoped models read their database connection from here, so code
 * never reaches another country's data by accident (MASTER_PLAN ADR 2).
 */
class CountryContext
{
    private ?Country $country = null;

    public function set(Country $country): void
    {
        $this->country = $country;
        Context::add('country', $country->code);
        setPermissionsTeamId($country->id);
    }

    public function clear(): void
    {
        $this->country = null;
        Context::forget('country');
        setPermissionsTeamId(null);
    }

    public function has(): bool
    {
        return $this->country !== null;
    }

    public function current(): Country
    {
        if ($this->country === null) {
            throw new CountryContextMissing;
        }

        return $this->country;
    }

    public function connectionName(): string
    {
        return $this->current()->connectionName();
    }

    /**
     * Run a callback as another country, then restore the previous one.
     *
     * @template TReturn
     *
     * @param  callable(Country): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Country $country, callable $callback): mixed
    {
        $previous = $this->country;
        $this->set($country);

        try {
            return $callback($country);
        } finally {
            $previous === null ? $this->clear() : $this->set($previous);
        }
    }
}
