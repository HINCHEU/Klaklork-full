<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A room code is an invite, not a credential. These tests pin down the boundary
 * between "holds the code" and "is seated at the table" — everything about
 * anyone's money belongs on the far side of it.
 */
class RoomAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function room(User $host, array $attributes = []): GameRoom
    {
        $room = GameRoom::create(array_merge([
            'code'         => GameRoom::generateCode(),
            'host_user_id' => $host->id,
            'bet_amount'   => 500,
            'max_players'  => 10,
            'status'       => 'waiting',
        ], $attributes));

        $room->players()->attach($host->id, ['joined_at' => now()]);

        return $room;
    }

    public function test_outsider_reading_a_room_never_sees_balances_or_bets(): void
    {
        $host   = User::factory()->create(['balance' => 7000]);
        $player = User::factory()->create(['balance' => 4200]);
        $room   = $this->room($host, ['status' => 'betting']);
        $room->players()->attach($player->id, ['joined_at' => now()]);

        Bet::create([
            'game_room_id' => $room->id,
            'user_id'      => $player->id,
            'animal_slot'  => 3,
            'amount'       => 500,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson("/api/games/{$room->code}")->assertOk();

        // Enough to render the join screen...
        $response->assertJsonPath('code', $room->code)
            ->assertJsonPath('players_count', 2)
            ->assertJsonPath('bets', []);

        // ...and nothing about the money on the table.
        $this->assertStringNotContainsString('balance', $response->getContent());
        $this->assertStringNotContainsString('4200', $response->getContent());
        $this->assertStringNotContainsString('animal_slot', $response->getContent());
    }

    public function test_member_reading_a_room_still_sees_the_full_table(): void
    {
        $host   = User::factory()->create(['balance' => 7000]);
        $player = User::factory()->create(['balance' => 4200]);
        $room   = $this->room($host, ['status' => 'betting']);
        $room->players()->attach($player->id, ['joined_at' => now()]);

        Sanctum::actingAs($player);

        $this->getJson("/api/games/{$room->code}")
            ->assertOk()
            ->assertJsonPath('players_count', 2)
            ->assertJsonFragment(['balance' => 4200]);
    }

    public function test_outsider_cannot_read_the_bet_board(): void
    {
        $host = User::factory()->create();
        $room = $this->room($host, ['status' => 'betting']);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/games/{$room->code}/bets")->assertForbidden();
    }

    public function test_member_can_read_the_bet_board(): void
    {
        $host   = User::factory()->create();
        $player = User::factory()->create();
        $room   = $this->room($host, ['status' => 'betting']);
        $room->players()->attach($player->id, ['joined_at' => now()]);

        Sanctum::actingAs($player);

        $this->getJson("/api/games/{$room->code}/bets")->assertOk();
    }

    public function test_outsider_cannot_bet_into_a_room_they_have_not_joined(): void
    {
        $host = User::factory()->create();
        $room = $this->room($host, ['status' => 'betting']);

        $outsider = User::factory()->create(['balance' => 10000]);
        Sanctum::actingAs($outsider);

        $this->postJson("/api/games/{$room->code}/bets", ['animal_slot' => 2])
            ->assertForbidden();

        $this->assertSame(10000, $outsider->fresh()->balance);
        $this->assertSame(0, $room->bets()->count());
    }

    public function test_outsider_cannot_announce_a_departure_from_a_room(): void
    {
        $host = User::factory()->create();
        $room = $this->room($host);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/games/{$room->code}/leave")->assertForbidden();

        // The roster is untouched — the host is still seated.
        $this->assertSame(1, $room->players()->count());
    }

    /** Only the host drives the round; a player cannot deal for the table. */
    public function test_non_host_cannot_drive_the_round(): void
    {
        $host   = User::factory()->create();
        $player = User::factory()->create();
        $room   = $this->room($host, ['status' => 'spinning']);
        $room->players()->attach($player->id, ['joined_at' => now()]);

        Sanctum::actingAs($player);

        $this->postJson("/api/games/{$room->code}/open-betting")->assertForbidden();
        $this->postJson("/api/games/{$room->code}/spin")->assertForbidden();
        $this->postJson("/api/games/{$room->code}/stop")->assertForbidden();

        $this->assertSame('spinning', $room->fresh()->status);
    }

    public function test_room_feed_is_only_authorized_for_players_in_that_room(): void
    {
        $host     = User::factory()->create();
        $room     = $this->room($host);
        $outsider = User::factory()->create();

        $callback = Broadcast::getChannels()['game.{code}'] ?? null;
        $this->assertNotNull($callback, 'game.{code} must have an authorization callback.');

        $this->assertTrue($callback($host, $room->code));
        $this->assertFalse($callback($outsider, $room->code));
        $this->assertFalse($callback($host, 'NOSUCH'));
    }

    public function test_room_feed_authorization_endpoint_rejects_a_guest(): void
    {
        $host = User::factory()->create();
        $room = $this->room($host);

        $this->postJson('/api/broadcasting/auth', [
            'socket_id'    => '1234.5678',
            'channel_name' => "private-game.{$room->code}",
        ])->assertUnauthorized();
    }
}
