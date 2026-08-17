<?php

namespace Tests\Feature;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The round is zero-sum: every riel a player wins comes out of the banker, and
 * every riel they lose goes into it. The phase transitions now claim the room
 * row before touching money, so these tests hold the economy to that contract.
 *
 * The underlying defects were races between two simultaneous requests, which
 * PHPUnit cannot reproduce against a single connection. What is covered here is
 * the observable contract the claims exist to protect — settle once, and never
 * accept a stake the player cannot cover or a round has moved past.
 */
class RoundIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private User $player;
    private GameRoom $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host   = User::factory()->create(['balance' => 10000]);
        $this->player = User::factory()->create(['balance' => 10000]);

        $this->room = GameRoom::create([
            'code'         => GameRoom::generateCode(),
            'host_user_id' => $this->host->id,
            'bet_amount'   => 500,
            'max_players'  => 10,
            'status'       => 'waiting',
        ]);

        $this->room->players()->attach($this->host->id, ['joined_at' => now()]);
        $this->room->players()->attach($this->player->id, ['joined_at' => now()]);
    }

    private function totalMoney(): int
    {
        return (int) User::sum('balance');
    }

    public function test_a_full_round_conserves_money_between_banker_and_player(): void
    {
        $before = $this->totalMoney();

        Sanctum::actingAs($this->host);
        $this->postJson("/api/games/{$this->room->code}/open-betting")->assertOk();

        Sanctum::actingAs($this->player);
        $this->postJson("/api/games/{$this->room->code}/bets", ['animal_slot' => 4])
            ->assertCreated()
            ->assertJsonPath('balance', 9500);

        Sanctum::actingAs($this->host);
        $this->postJson("/api/games/{$this->room->code}/spin")->assertOk();
        $response = $this->postJson("/api/games/{$this->room->code}/stop")->assertOk();

        // Whatever the dice did, the table's money only moved sideways.
        $this->assertSame($before, $this->totalMoney());

        $settlement = $response->json('settlement');
        $playerNet  = collect($settlement['players'])->sum('net');
        $this->assertSame(-$playerNet, $settlement['host']['net'], 'The banker is the counterparty to every player.');

        $this->assertSame('finished', $this->room->fresh()->status);
        $this->assertCount(3, $response->json('room.result'));
    }

    public function test_a_round_is_settled_only_once(): void
    {
        Sanctum::actingAs($this->host);
        $this->postJson("/api/games/{$this->room->code}/open-betting")->assertOk();

        Sanctum::actingAs($this->player);
        $this->postJson("/api/games/{$this->room->code}/bets", ['animal_slot' => 4])->assertCreated();

        Sanctum::actingAs($this->host);
        $this->postJson("/api/games/{$this->room->code}/spin")->assertOk();
        $this->postJson("/api/games/{$this->room->code}/stop")->assertOk();

        $hostAfterFirst   = $this->host->fresh()->balance;
        $playerAfterFirst = $this->player->fresh()->balance;

        // A second stop must not pay the same bets out again.
        $this->postJson("/api/games/{$this->room->code}/stop")->assertStatus(422);

        $this->assertSame($hostAfterFirst, $this->host->fresh()->balance);
        $this->assertSame($playerAfterFirst, $this->player->fresh()->balance);
    }

    public function test_a_stake_cannot_land_after_the_host_has_spun(): void
    {
        Sanctum::actingAs($this->host);
        $this->postJson("/api/games/{$this->room->code}/open-betting")->assertOk();
        $this->postJson("/api/games/{$this->room->code}/spin")->assertOk();

        Sanctum::actingAs($this->player);
        $this->postJson("/api/games/{$this->room->code}/bets", ['animal_slot' => 4])
            ->assertStatus(422);

        $this->assertSame(10000, $this->player->fresh()->balance);
        $this->assertSame(0, $this->room->bets()->count());
    }

    public function test_a_player_cannot_stake_more_than_they_hold(): void
    {
        $this->player->update(['balance' => 100]);

        Sanctum::actingAs($this->host);
        $this->postJson("/api/games/{$this->room->code}/open-betting")->assertOk();

        Sanctum::actingAs($this->player);
        $this->postJson("/api/games/{$this->room->code}/bets", ['animal_slot' => 4])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient balance.');

        $this->assertSame(100, $this->player->fresh()->balance);
        $this->assertSame(0, $this->room->bets()->count());
    }

    public function test_betting_cannot_be_reopened_mid_round(): void
    {
        Sanctum::actingAs($this->host);
        $this->postJson("/api/games/{$this->room->code}/open-betting")->assertOk();

        Sanctum::actingAs($this->player);
        $this->postJson("/api/games/{$this->room->code}/bets", ['animal_slot' => 4])->assertCreated();

        // Reopening here would delete a live stake the player has already paid for.
        Sanctum::actingAs($this->host);
        $this->postJson("/api/games/{$this->room->code}/open-betting")->assertStatus(422);

        $this->assertSame(1, $this->room->bets()->count());
        $this->assertSame(9500, $this->player->fresh()->balance);
    }

    public function test_repeated_stakes_on_one_slot_accumulate(): void
    {
        Sanctum::actingAs($this->host);
        $this->postJson("/api/games/{$this->room->code}/open-betting")->assertOk();

        Sanctum::actingAs($this->player);
        $this->postJson("/api/games/{$this->room->code}/bets", ['animal_slot' => 2])->assertCreated();
        $this->postJson("/api/games/{$this->room->code}/bets", ['animal_slot' => 2])
            ->assertCreated()
            ->assertJsonPath('bet.amount', 1000)
            ->assertJsonPath('balance', 9000);

        $this->assertSame(1, $this->room->bets()->count());
    }
}
