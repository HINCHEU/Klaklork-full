<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A player who closes the tab never calls /leave. These tests pin down that a
 * room whose seated players have all gone quiet for GameRoom::IDLE_MINUTES is
 * closed — and that closing it never swallows anyone's unsettled stake.
 */
class IdleRoomCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function room(User $host, array $attributes = []): GameRoom
    {
        $room = GameRoom::create(array_merge([
            'code'           => GameRoom::generateCode(),
            'host_user_id'   => $host->id,
            'bet_amount'     => 500,
            'max_players'    => 10,
            'status'         => 'waiting',
            'last_active_at' => now(),
        ], $attributes));

        $room->players()->attach($host->id, ['joined_at' => now()]);

        return $room;
    }

    public function test_room_idle_for_thirty_minutes_is_closed(): void
    {
        $room = $this->room(User::factory()->create());

        $this->travel(GameRoom::IDLE_MINUTES + 1)->minutes();
        $this->artisan('rooms:close-idle')->assertSuccessful();

        $this->assertModelMissing($room);
        $this->assertDatabaseMissing('game_players', ['game_room_id' => $room->id]);
    }

    public function test_room_is_kept_before_the_cutoff(): void
    {
        $room = $this->room(User::factory()->create());

        $this->travel(GameRoom::IDLE_MINUTES - 1)->minutes();
        $this->artisan('rooms:close-idle')->assertSuccessful();

        $this->assertModelExists($room);
    }

    public function test_a_seated_players_poll_keeps_the_room_open(): void
    {
        $host = User::factory()->create();
        $room = $this->room($host);

        $this->travel(GameRoom::IDLE_MINUTES - 5)->minutes();
        Sanctum::actingAs($host);
        $this->getJson("/api/games/{$room->code}")->assertOk();

        $this->travel(10)->minutes();
        $this->artisan('rooms:close-idle')->assertSuccessful();

        $this->assertModelExists($room);
    }

    public function test_an_outsider_reading_the_preview_does_not_keep_the_room_open(): void
    {
        $room = $this->room(User::factory()->create());

        $this->travel(GameRoom::IDLE_MINUTES - 5)->minutes();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/games/{$room->code}")->assertOk();

        $this->travel(10)->minutes();
        $this->artisan('rooms:close-idle')->assertSuccessful();

        $this->assertModelMissing($room);
    }

    public function test_room_abandoned_mid_round_refunds_open_stakes(): void
    {
        $host   = User::factory()->create(['balance' => 10000]);
        $player = User::factory()->create(['balance' => 4000]);
        $room   = $this->room($host, ['status' => 'betting']);
        $room->players()->attach($player->id, ['joined_at' => now()]);

        // The stake has already left the player's balance, as BetController does.
        Bet::create([
            'game_room_id' => $room->id,
            'user_id'      => $player->id,
            'animal_slot'  => 2,
            'amount'       => 1000,
        ]);

        $this->travel(GameRoom::IDLE_MINUTES + 1)->minutes();
        $this->artisan('rooms:close-idle')->assertSuccessful();

        $this->assertModelMissing($room);
        $this->assertSame(5000, (int) $player->fresh()->balance);
        $this->assertSame(10000, (int) $host->fresh()->balance);
    }

    public function test_a_finished_rounds_settled_bets_are_not_refunded_again(): void
    {
        $host   = User::factory()->create(['balance' => 10000]);
        $player = User::factory()->create(['balance' => 4000]);
        $room   = $this->room($host, ['status' => 'finished', 'result' => [1, 1, 1]]);
        $room->players()->attach($player->id, ['joined_at' => now()]);

        Bet::create([
            'game_room_id' => $room->id,
            'user_id'      => $player->id,
            'animal_slot'  => 2,
            'amount'       => 1000,
            'won_amount'   => -1000,
        ]);

        $this->travel(GameRoom::IDLE_MINUTES + 1)->minutes();
        $this->artisan('rooms:close-idle')->assertSuccessful();

        $this->assertModelMissing($room);
        $this->assertSame(4000, (int) $player->fresh()->balance);
    }

    public function test_a_player_returning_just_before_the_close_keeps_the_room(): void
    {
        $room = $this->room(User::factory()->create());

        $this->travel(GameRoom::IDLE_MINUTES + 1)->minutes();
        $cutoff = now()->subMinutes(GameRoom::IDLE_MINUTES);

        // The sweep has already picked this room up as stale...
        $stale = GameRoom::find($room->id);

        // ...when a seated player's poll lands.
        GameRoom::whereKey($room->id)->update(['last_active_at' => now()]);

        $this->assertFalse($stale->closeIfIdleSince($cutoff));
        $this->assertModelExists($room);
    }

    public function test_room_without_recorded_activity_falls_back_to_updated_at(): void
    {
        $room = $this->room(User::factory()->create(), ['last_active_at' => null]);

        $this->artisan('rooms:close-idle')->assertSuccessful();
        $this->assertModelExists($room);

        $this->travel(GameRoom::IDLE_MINUTES + 1)->minutes();
        $this->artisan('rooms:close-idle')->assertSuccessful();
        $this->assertModelMissing($room);
    }
}
