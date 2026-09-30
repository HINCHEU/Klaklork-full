<?php

namespace Tests\Feature;

use App\Events\HostChanged;
use App\Events\PlayerLeft;
use App\Models\Bet;
use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Closing the tab never reaches /leave. A player whose tab has gone silent for
 * GameRoom::AWAY_MINUTES has left, and loses the seat as if they had pressed
 * Leave — unless nobody is left at all, which is the idle cleanup's job.
 */
class AbsentPlayerTest extends TestCase
{
    use RefreshDatabase;

    private function seat(GameRoom $room, User $user): void
    {
        $room->players()->attach($user->id, ['joined_at' => now(), 'last_seen_at' => now()]);
    }

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

        $this->seat($room, $host);

        return $room;
    }

    /** Move time on, with $present still polling the room. */
    private function awayFor(int $minutes, GameRoom $room, User ...$present): void
    {
        $this->travel($minutes)->minutes();

        foreach ($present as $user) {
            Sanctum::actingAs($user);
            $this->getJson("/api/games/{$room->code}")->assertOk();
        }
    }

    private function sweep(): void
    {
        $this->artisan('rooms:remove-absent-players')->assertSuccessful();
    }

    public function test_a_player_whose_tab_closed_loses_the_seat(): void
    {
        Event::fake([PlayerLeft::class, HostChanged::class]);

        $host  = User::factory()->create();
        $gone  = User::factory()->create();
        $room  = $this->room($host);
        $this->seat($room, $gone);

        $this->awayFor(GameRoom::AWAY_MINUTES + 1, $room, $host);
        $this->sweep();

        $this->assertFalse($room->hasPlayer($gone->id));
        $this->assertTrue($room->hasPlayer($host->id));
        Event::assertDispatched(PlayerLeft::class, fn ($e) => $e->user->is($gone));
        Event::assertNotDispatched(HostChanged::class);
    }

    public function test_a_player_still_polling_keeps_the_seat(): void
    {
        $host   = User::factory()->create();
        $player = User::factory()->create();
        $room   = $this->room($host);
        $this->seat($room, $player);

        $this->awayFor(GameRoom::AWAY_MINUTES + 1, $room, $host, $player);
        $this->sweep();

        $this->assertTrue($room->hasPlayer($player->id));
    }

    public function test_a_player_away_for_less_than_the_cutoff_keeps_the_seat(): void
    {
        $host   = User::factory()->create();
        $player = User::factory()->create();
        $room   = $this->room($host);
        $this->seat($room, $player);

        $this->awayFor(GameRoom::AWAY_MINUTES - 1, $room, $host);
        $this->sweep();

        $this->assertTrue($room->hasPlayer($player->id));
    }

    public function test_a_freed_seat_lets_someone_new_into_a_full_room(): void
    {
        $host = User::factory()->create();
        $gone = User::factory()->create();
        $room = $this->room($host, ['max_players' => 2]);
        $this->seat($room, $gone);

        $this->awayFor(GameRoom::AWAY_MINUTES + 1, $room, $host);
        $this->sweep();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/games/{$room->code}/join")->assertOk();
    }

    public function test_an_absent_host_leaves_and_the_bank_passes_on(): void
    {
        Event::fake([PlayerLeft::class, HostChanged::class]);

        $host   = User::factory()->create();
        $player = User::factory()->create();
        $room   = $this->room($host);
        $this->seat($room, $player);

        $this->awayFor(GameRoom::AWAY_MINUTES + 1, $room, $player);
        $this->sweep();

        $this->assertFalse($room->hasPlayer($host->id));
        $this->assertSame($player->id, $room->fresh()->host_user_id);
        Event::assertDispatched(HostChanged::class, fn ($e) => $e->host->is($player));
    }

    public function test_when_everyone_is_gone_the_room_is_left_for_the_idle_cleanup(): void
    {
        $host  = User::factory()->create();
        $other = User::factory()->create();
        $room  = $this->room($host);
        $this->seat($room, $other);

        $this->awayFor(GameRoom::AWAY_MINUTES + 1, $room);
        $this->sweep();

        // Nobody is removed — a player coming back still finds their seat...
        $this->assertTrue($room->hasPlayer($host->id));
        $this->assertTrue($room->hasPlayer($other->id));

        // ...and the room still closes on the idle rule.
        $this->travel(GameRoom::IDLE_MINUTES)->minutes();
        $this->artisan('rooms:close-idle')->assertSuccessful();
        $this->assertModelMissing($room);
    }

    public function test_a_newcomer_to_an_abandoned_room_clears_it_and_takes_the_bank(): void
    {
        $host  = User::factory()->create();
        $other = User::factory()->create();
        $room  = $this->room($host);
        $this->seat($room, $other);

        $this->travel(GameRoom::AWAY_MINUTES + 1)->minutes();

        $newcomer = User::factory()->create();
        Sanctum::actingAs($newcomer);
        $this->postJson("/api/games/{$room->code}/join")->assertOk();

        $this->sweep();

        $this->assertSame([$newcomer->id], $room->players()->pluck('users.id')->all());
        $this->assertSame($newcomer->id, $room->fresh()->host_user_id);
    }

    /** A locked room, with $gone unseated by the sweep while $host stays. */
    private function lockedRoomWithAbsentPlayer(User $host, User $gone): GameRoom
    {
        $room = $this->room($host, ['password' => 'secret-pass']);
        $this->seat($room, $gone);

        $this->awayFor(GameRoom::AWAY_MINUTES + 1, $room, $host);
        $this->sweep();
        $this->assertFalse($room->hasPlayer($gone->id));

        return $room;
    }

    public function test_a_player_unseated_while_away_returns_to_a_locked_room_without_the_password(): void
    {
        $host = User::factory()->create();
        $gone = User::factory()->create();
        $room = $this->lockedRoomWithAbsentPlayer($host, $gone);

        Sanctum::actingAs($gone);
        $this->postJson("/api/games/{$room->code}/join")->assertOk();

        $this->assertTrue($room->hasPlayer($gone->id));
    }

    public function test_the_return_pass_is_spent_on_use(): void
    {
        $host = User::factory()->create();
        $gone = User::factory()->create();
        $room = $this->lockedRoomWithAbsentPlayer($host, $gone);

        Sanctum::actingAs($gone);
        $this->postJson("/api/games/{$room->code}/join")->assertOk();
        $this->postJson("/api/games/{$room->code}/leave")->assertOk();

        $this->postJson("/api/games/{$room->code}/join")
            ->assertForbidden()
            ->assertJsonPath('password_required', true);
    }

    public function test_the_return_pass_expires(): void
    {
        $host = User::factory()->create();
        $gone = User::factory()->create();
        $room = $this->lockedRoomWithAbsentPlayer($host, $gone);

        $this->awayFor(GameRoom::RETURN_PASS_MINUTES + 1, $room, $host);

        Sanctum::actingAs($gone);
        $this->postJson("/api/games/{$room->code}/join")
            ->assertForbidden()
            ->assertJsonPath('password_required', true);
    }

    public function test_pressing_leave_grants_no_way_back_without_the_password(): void
    {
        $host   = User::factory()->create();
        $player = User::factory()->create();
        $room   = $this->room($host, ['password' => 'secret-pass']);
        $this->seat($room, $player);

        Sanctum::actingAs($player);
        $this->postJson("/api/games/{$room->code}/leave")->assertOk();

        $this->postJson("/api/games/{$room->code}/join")
            ->assertForbidden()
            ->assertJsonPath('password_required', true);
    }

    public function test_a_return_pass_does_not_open_a_different_room(): void
    {
        $host  = User::factory()->create();
        $gone  = User::factory()->create();
        $this->lockedRoomWithAbsentPlayer($host, $gone);

        $other = $this->room(User::factory()->create(), ['password' => 'other-pass']);

        Sanctum::actingAs($gone);
        $this->postJson("/api/games/{$other->code}/join")
            ->assertForbidden()
            ->assertJsonPath('password_required', true);
    }

    public function test_a_bettor_who_leaves_mid_round_is_still_settled(): void
    {
        $host   = User::factory()->create(['balance' => 10000]);
        $bettor = User::factory()->create(['balance' => 4500]);   // 500 already staked
        $room   = $this->room($host, ['status' => 'spinning']);
        $this->seat($room, $bettor);

        Bet::create(['game_room_id' => $room->id, 'user_id' => $bettor->id, 'animal_slot' => 3, 'amount' => 500]);

        $this->awayFor(GameRoom::AWAY_MINUTES + 1, $room, $host);
        $this->sweep();
        $this->assertFalse($room->hasPlayer($bettor->id));

        Sanctum::actingAs($host);
        $this->postJson("/api/games/{$room->code}/stop")->assertOk();

        // Win or lose, the stake was settled — the room did not keep it. The
        // round is zero-sum: 10,000 + 4,500 + the 500 staked is all still there.
        $bet = Bet::where('user_id', $bettor->id)->first();
        $this->assertNotNull($bet->won_amount);
        $this->assertSame(15000, (int) $host->fresh()->balance + (int) $bettor->fresh()->balance);
    }
}
