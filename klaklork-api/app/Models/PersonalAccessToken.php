<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token, minus most of its last_used_at writes.
 *
 * Sanctum stamps last_used_at on every authenticated request, so every poll and
 * bet costs an extra UPDATE. Under load that is a real share of MySQL's CPU.
 * What reads it — the online count (a 2-minute window) and guest pruning (days)
 * — only needs it to the minute, so a stamp less than a minute after the last
 * one is dropped.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public const STAMP_EVERY_SECONDS = 60;

    public function save(array $options = []): bool
    {
        $last = $this->getOriginal('last_used_at');

        if ($this->exists
            && array_keys($this->getDirty()) === ['last_used_at']
            && $last !== null
            && $this->last_used_at->between($last, $last->copy()->addSeconds(self::STAMP_EVERY_SECONDS))) {
            return true;
        }

        return parent::save($options);
    }
}
