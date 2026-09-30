<?php

use App\Models\GameRoom;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Close rooms nobody has been in for GameRoom::IDLE_MINUTES.
 *
 * Players who close the tab never call /leave, so "last one out" alone leaves
 * abandoned rooms sitting in the lobby forever. Every seated player's poll
 * refreshes last_active_at; once that has gone stale, the table is empty.
 */
Artisan::command('rooms:close-idle', function () {
    $cutoff = now()->subMinutes(GameRoom::IDLE_MINUTES);
    $closed = 0;

    // A room that never recorded activity falls back to its last change.
    // lazyById, not chunk: rows are deleted as we go, and offset paging would
    // skip past the ones that slide into the gap.
    GameRoom::whereRaw('coalesce(last_active_at, updated_at) < ?', [$cutoff])
        ->lazyById()
        ->each(function (GameRoom $room) use ($cutoff, &$closed) {
            // Re-check against the live row: a player may have come back
            // between the query and now.
            $fresh = $room->fresh();

            if ($fresh === null || ($fresh->last_active_at ?? $fresh->updated_at)->gte($cutoff)) {
                return;
            }

            $fresh->close();
            $closed++;
        });

    $this->info("Closed {$closed} idle room(s).");
})->purpose('Close rooms with no active players for '.GameRoom::IDLE_MINUTES.' minutes');

Schedule::command('rooms:close-idle')->everyMinute()->withoutOverlapping();
