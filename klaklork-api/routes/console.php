<?php

use App\Events\HostChanged;
use App\Events\PlayerLeft;
use App\Models\GameRoom;
use App\Models\User;
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
 * Unseat players whose tab has been silent for GameRoom::AWAY_MINUTES.
 *
 * Closing the tab never reaches /leave, so without this a player keeps their
 * seat forever — counting against max_players and, if they were the host,
 * leaving everyone else at a table only they could run. Removal works exactly
 * like pressing Leave, bank handoff included.
 */
Artisan::command('rooms:remove-absent-players', function () {
    $cutoff  = now()->subMinutes(GameRoom::AWAY_MINUTES);
    $removed = 0;

    // Rooms with at least one quiet seat. Who actually goes, and who takes the
    // bank, is decided under the room lock in removeAbsentPlayersSince.
    GameRoom::whereExists(fn ($q) => $q->select(DB::raw(1))
            ->from('game_players')
            ->whereColumn('game_players.game_room_id', 'game_rooms.id')
            ->whereRaw('coalesce(game_players.last_seen_at, game_players.joined_at) < ?', [$cutoff]))
        ->lazyById()
        ->each(function (GameRoom $room) use ($cutoff, &$removed) {
            $outcome = $room->removeAbsentPlayersSince($cutoff);

            if ($outcome === null) {
                return;
            }

            $removed += $outcome['left']->count();
            $room = $room->fresh();

            foreach ($outcome['left'] as $user) {
                try { broadcast(new PlayerLeft($room, $user)); } catch (\Throwable) {}
            }

            if ($outcome['host'] !== null) {
                try { broadcast(new HostChanged($room, $outcome['host'], $outcome['round_voided'])); } catch (\Throwable) {}
            }
        });

    $this->info("Removed {$removed} absent player(s).");
})->purpose('Unseat players whose tab has been silent for '.GameRoom::AWAY_MINUTES.' minutes');

// The overlap lock must expire quickly. Its default lifetime is 24 hours, and
// storage/ (where the file cache keeps it) is a persistent volume: a deploy
// that stops the container mid-sweep would otherwise leave the lock behind and
// silently switch both sweeps off for a day. A sweep takes about a second.
Schedule::command('rooms:close-idle')->everyMinute()->withoutOverlapping(2);
Schedule::command('rooms:remove-absent-players')->everyMinute()->withoutOverlapping(2);

// Guests inactive for User::GUEST_RETENTION_DAYS; see User::prunable(). Off-peak,
// and with the same short-lived lock as the sweeps above, for the same reason.
Schedule::command('model:prune', ['--model' => [User::class]])
    ->dailyAt('04:00')
    ->withoutOverlapping(60);
