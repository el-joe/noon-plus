<?php

namespace App\Domains\Migration\Steps;

/**
 * A step whose tables are all archived, folded or dropped by rule (streams:
 * see data-migration-plan §4.3 and §13).
 */
class SimpleStep extends Step
{
    private string $stepName = '';

    public function named(string $name): void
    {
        $this->stepName = $name;
    }

    public function name(): string
    {
        return $this->stepName;
    }
}
