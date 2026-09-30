<?php

use App\Events\HostChanged;
use App\Models\GameRoom;
use Illuminate\Support\Facades\DB;
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

/**
 * Hand the bank on when the host has gone silent for GameRoom::HOST_AWAY_MINUTES.
 *
 * Only the host can open betting, spin, or stop, so a host who closes the tab
 * leaves everyone else at a table that can never move again — possibly mid-spin
 * with their stakes held. The earliest-joined player still polling takes over.
 */
Artisan::command('rooms:reassign-absent-hosts', function () {
    $cutoff = now()->subMinutes(GameRoom::HOST_AWAY_MINUTES);
    $moved  = 0;

    // Rooms whose host's seat has gone quiet. The recheck, and the choice of
    // who takes over, happen under the room lock in reassignHostIfAbsentSince.
    GameRoom::whereExists(fn ($q) => $q->select(DB::raw(1))
            ->from('game_players')
            ->whereColumn('game_players.game_room_id', 'game_rooms.id')
            ->whereColumn('game_players.user_id', 'game_rooms.host_user_id')
            ->whereRaw('coalesce(game_players.last_seen_at, game_players.joined_at) < ?', [$cutoff]))
        ->lazyById()
        ->each(function (GameRoom $room) use ($cutoff, &$moved) {
            $handoff = $room->reassignHostIfAbsentSince($cutoff);

            if ($handoff === null) {
                return;
            }

            $moved++;

            try { broadcast(new HostChanged($room->fresh(), $handoff['host'], $handoff['round_voided'])); } catch (\Throwable) {}
        });

    $this->info("Reassigned {$moved} absent host(s).");
})->purpose('Hand the bank to an active player when the host has been gone for '.GameRoom::HOST_AWAY_MINUTES.' minutes');

Schedule::command('rooms:close-idle')->everyMinute()->withoutOverlapping();
Schedule::command('rooms:reassign-absent-hosts')->everyMinute()->withoutOverlapping();
