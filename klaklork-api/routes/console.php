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

    // Two branches rather than coalesce(), so the last_active_at index is used.
    // lazyById, not chunk: rows are deleted as we go, and offset paging would
    // skip past the ones that slide into the gap.
    GameRoom::where('last_active_at', '<', $cutoff)
        ->orWhere(fn ($q) => $q->whereNull('last_active_at')->where('updated_at', '<', $cutoff))
        ->lazyById()
        ->each(function (GameRoom $room) use ($cutoff, &$closed) {
            if ($room->closeIfIdleSince($cutoff)) {
                $closed++;
            }
        });

    $this->info("Closed {$closed} idle room(s).");
})->purpose('Close rooms with no active players for '.GameRoom::IDLE_MINUTES.' minutes');

Schedule::command('rooms:close-idle')->everyMinute()->withoutOverlapping();
