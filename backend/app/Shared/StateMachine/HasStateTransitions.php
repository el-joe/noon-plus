<?php

namespace App\Shared\StateMachine;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * For models moved by {@see StateMachine}: their history rows.
 */
trait HasStateTransitions
{
    public function transitionSubject(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * @return HasMany<StateTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(StateTransition::class, 'subject_id')
            ->where('subject_type', $this->transitionSubject())
            ->orderBy('id');
    }
}
