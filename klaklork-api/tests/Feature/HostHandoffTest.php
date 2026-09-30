<?php

namespace Tests\Feature;

use App\Events\HostChanged;
use App\Models\Bet;
use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Only the host can run a round, so a host who walks away must not strand the
 * table — and handing the bank on must never move money nobody agreed to.
 */
class HostHandoffTest extends TestCase
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

    public function test_silent_host_is_replaced_by_the_earliest_active_player(): void
    {
        Event::fake([HostChanged::class]);

        $host  = User::factory()->create();
        $first = User::factory()->create();
        $room  = $this->room($host);
        $this->seat($room, $first);
        $this->travel(1)->seconds();
        $this->seat($room, User::factory()->create());

        $this->awayFor(GameRoom::HOST_AWAY_MINUTES + 1, $room, ...$room->players()->where('users.id', '!=', $host->id)->get());
        $this->artisan('rooms:reassign-absent-hosts')->assertSuccessful();

        $this->assertSame($first->id, $room->fresh()->host_user_id);
        Event::assertDispatched(HostChanged::class, fn ($e) => $e->host->is($first) && ! $e->roundVoided);
    }

    public function test_host_who_is_still_polling_keeps_the_bank(): void
    {
        $host   = User::factory()->create();
        $player = User::factory()->create();
        $room   = $this->room($host);
        $this->seat($room, $player);

        $this->awayFor(GameRoom::HOST_AWAY_MINUTES + 1, $room, $host, $player);
        $this->artisan('rooms:reassign-absent-hosts')->assertSuccessful();

        $this->assertSame($host->id, $room->fresh()->host_user_id);
    }

    public function test_players_who_are_also_gone_are_not_handed_the_bank(): void
    {
        $host   = User::factory()->create();
        $absent = User::factory()->create();
        $active = User::factory()->create();
        $room   = $this->room($host);
        $this->seat($room, $absent);   // joined earlier, but has left the tab
        $this->travel(1)->seconds();
        $this->seat($room, $active);

        $this->awayFor(GameRoom::HOST_AWAY_MINUTES + 1, $room, $active);
        $this->artisan('rooms:reassign-absent-hosts')->assertSuccessful();

        $this->assertSame($active->id, $room->fresh()->host_user_id);
    }

    public function test_nothing_changes_when_nobody_is_active(): void
    {
        $host = User::factory()->create();
        $room = $this->room($host);
        $this->seat($room, User::factory()->create());

        $this->awayFor(GameRoom::HOST_AWAY_MINUTES + 1, $room);
        $this->artisan('rooms:reassign-absent-hosts')->assertSuccessful();

        $this->assertSame($host->id, $room->fresh()->host_user_id);
    }

    public function test_handoff_mid_round_calls_the_round_off_and_refunds_every_stake(): void
    {
        Event::fake([HostChanged::class]);

        $host   = User::factory()->create(['balance' => 10000]);
        $bettor = User::factory()->create(['balance' => 3000]);
        $other  = User::factory()->create(['balance' => 2000]);
        $room   = $this->room($host, ['status' => 'spinning']);
        $this->seat($room, $bettor);
        $this->seat($room, $other);

        // Stakes already left both balances, as BetController does.
        foreach ([[$bettor, 1000], [$other, 500]] as [$user, $amount]) {
            Bet::create(['game_room_id' => $room->id, 'user_id' => $user->id, 'animal_slot' => 4, 'amount' => $amount]);
        }

        $this->awayFor(GameRoom::HOST_AWAY_MINUTES + 1, $room, $bettor, $other);
        $this->artisan('rooms:reassign-absent-hosts')->assertSuccessful();

        $room->refresh();
        $this->assertSame($bettor->id, $room->host_user_id);
        $this->assertSame('waiting', $room->status);
        $this->assertSame(0, $room->bets()->count());
        $this->assertSame(4000, (int) $bettor->fresh()->balance);
        $this->assertSame(2500, (int) $other->fresh()->balance);
        $this->assertSame(10000, (int) $host->fresh()->balance);
        Event::assertDispatched(HostChanged::class, fn ($e) => $e->roundVoided);
    }

    public function test_handoff_after_a_finished_round_keeps_its_result(): void
    {
        $host   = User::factory()->create();
        $player = User::factory()->create(['balance' => 4000]);
        $room   = $this->room($host, ['status' => 'finished', 'result' => [2, 2, 5]]);
        $this->seat($room, $player);

        Bet::create(['game_room_id' => $room->id, 'user_id' => $player->id, 'animal_slot' => 1, 'amount' => 500, 'won_amount' => -500]);

        $this->awayFor(GameRoom::HOST_AWAY_MINUTES + 1, $room, $player);
        $this->artisan('rooms:reassign-absent-hosts')->assertSuccessful();

        $room->refresh();
        $this->assertSame($player->id, $room->host_user_id);
        $this->assertSame('finished', $room->status);
        $this->assertSame([2, 2, 5], $room->result);
        $this->assertSame(4000, (int) $player->fresh()->balance);
    }

    public function test_host_leaving_mid_round_also_refunds_instead_of_passing_on_the_bets(): void
    {
        $host   = User::factory()->create(['balance' => 10000]);
        $player = User::factory()->create(['balance' => 3000]);
        $room   = $this->room($host, ['status' => 'betting']);
        $this->seat($room, $player);

        Bet::create(['game_room_id' => $room->id, 'user_id' => $player->id, 'animal_slot' => 2, 'amount' => 1000]);

        Sanctum::actingAs($host);
        $this->postJson("/api/games/{$room->code}/leave")->assertOk();

        $room->refresh();
        $this->assertSame($player->id, $room->host_user_id);
        $this->assertSame('waiting', $room->status);
        $this->assertSame(0, $room->bets()->count());
        $this->assertSame(4000, (int) $player->fresh()->balance);
    }

    public function test_new_host_can_run_the_next_round(): void
    {
        $host   = User::factory()->create();
        $player = User::factory()->create();
        $room   = $this->room($host, ['status' => 'spinning']);
        $this->seat($room, $player);

        $this->awayFor(GameRoom::HOST_AWAY_MINUTES + 1, $room, $player);
        $this->artisan('rooms:reassign-absent-hosts')->assertSuccessful();

        Sanctum::actingAs($player);
        $this->postJson("/api/games/{$room->code}/open-betting")->assertOk();
    }
}
