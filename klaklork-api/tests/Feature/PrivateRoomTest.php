<?php

namespace Tests\Feature;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateRoomTest extends TestCase
{
    use RefreshDatabase;

    private function createRoom(User $host, ?string $password = null): GameRoom
    {
        Sanctum::actingAs($host);

        $payload = ['bet_amount' => 500, 'max_players' => 10];

        if ($password !== null) {
            $payload['password'] = $password;
        }

        $code = $this->postJson('/api/games', $payload)->assertCreated()->json('code');

        return GameRoom::where('code', $code)->firstOrFail();
    }

    public function test_a_room_created_with_a_password_is_locked_and_stores_only_a_hash(): void
    {
        $room = $this->createRoom(User::factory()->create(), 'letmein');

        $this->assertTrue($room->is_private);
        $this->assertNotSame('letmein', $room->password, 'The password must never be stored as written.');
        $this->assertTrue(Hash::check('letmein', $room->password));
    }

    public function test_a_room_created_without_a_password_stays_open(): void
    {
        $room = $this->createRoom(User::factory()->create());

        $this->assertFalse($room->is_private);
        $this->assertNull($room->password);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/games/{$room->code}/join")->assertOk();
    }

    /** The hash must not appear anywhere a client can read. */
    public function test_the_password_hash_never_leaves_the_server(): void
    {
        $host = User::factory()->create();
        $room = $this->createRoom($host, 'letmein');

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $responses = [
            'lobby listing'   => $this->getJson('/api/games'),
            'outsider view'   => $this->getJson("/api/games/{$room->code}"),
        ];

        Sanctum::actingAs($host);
        $responses['member view'] = $this->getJson("/api/games/{$room->code}");
        $responses['creation']    = $this->postJson('/api/games', [
            'bet_amount' => 500, 'max_players' => 4, 'password' => 'letmein',
        ]);

        foreach ($responses as $label => $response) {
            $body = $response->getContent();

            $this->assertStringNotContainsString('letmein', $body, "Plaintext password leaked in {$label}.");
            $this->assertStringNotContainsString('$2y$', $body, "Password hash leaked in {$label}.");
            $this->assertStringNotContainsString('"password"', $body, "Password field exposed in {$label}.");
        }
    }

    /** Outsiders are told a room is locked — that is not the secret. */
    public function test_a_locked_room_is_advertised_as_locked(): void
    {
        $room = $this->createRoom(User::factory()->create(), 'letmein');

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/games/{$room->code}")
            ->assertOk()
            ->assertJsonPath('is_private', true);

        $this->getJson('/api/games')
            ->assertOk()
            ->assertJsonPath('0.is_private', true);
    }

    public function test_joining_a_locked_room_without_a_password_is_refused(): void
    {
        $room = $this->createRoom(User::factory()->create(), 'letmein');

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->postJson("/api/games/{$room->code}/join")
            ->assertForbidden()
            ->assertJsonPath('password_required', true)
            ->assertJsonPath('reason', 'missing');

        $this->assertFalse($room->hasPlayer($outsider->id));
    }

    public function test_joining_a_locked_room_with_the_wrong_password_is_refused(): void
    {
        $room = $this->createRoom(User::factory()->create(), 'letmein');

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->postJson("/api/games/{$room->code}/join", ['password' => 'letmein!'])
            ->assertForbidden()
            ->assertJsonPath('password_required', true)
            ->assertJsonPath('reason', 'invalid');

        $this->assertFalse($room->hasPlayer($outsider->id));
    }

    public function test_the_right_password_gets_a_seat(): void
    {
        $room = $this->createRoom(User::factory()->create(), 'letmein');

        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $this->postJson("/api/games/{$room->code}/join", ['password' => 'letmein'])->assertOk();

        $this->assertTrue($room->hasPlayer($player->id));

        // And now the full table is readable, as for any member.
        $this->getJson("/api/games/{$room->code}")
            ->assertOk()
            ->assertJsonPath('players_count', 2);
    }

    /**
     * The lock is for getting a seat, not for keeping it. A refresh, a closed
     * tab, or a re-opened invite link must not strand someone already playing.
     */
    public function test_a_seated_player_never_has_to_re_enter_the_password(): void
    {
        $room   = $this->createRoom(User::factory()->create(), 'letmein');
        $player = User::factory()->create();

        Sanctum::actingAs($player);
        $this->postJson("/api/games/{$room->code}/join", ['password' => 'letmein'])->assertOk();

        // Reconnect with no password at all.
        $this->postJson("/api/games/{$room->code}/join")->assertOk();
        $this->assertTrue($room->hasPlayer($player->id));
    }

    /** The host is attached at creation, so they are already seated. */
    public function test_the_host_never_has_to_type_their_own_password(): void
    {
        $host = User::factory()->create();
        $room = $this->createRoom($host, 'letmein');

        Sanctum::actingAs($host);
        $this->postJson("/api/games/{$room->code}/join")->assertOk();
    }

    /** A lock is not a substitute for the membership rule it sits in front of. */
    public function test_a_locked_room_still_withholds_the_table_from_outsiders(): void
    {
        $host = User::factory()->create(['balance' => 7777]);
        $room = $this->createRoom($host, 'letmein');

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson("/api/games/{$room->code}")->assertOk();

        $this->assertStringNotContainsString('7777', $response->getContent());
        $this->assertSame([], $response->json('bets'));

        $this->getJson("/api/games/{$room->code}/bets")->assertForbidden();
    }

    public static function invalidPasswords(): array
    {
        return [
            'too short' => ['abc'],
            'too long'  => [str_repeat('a', 73)],
            'not a string' => [['a', 'b']],
        ];
    }

    /**
     * @dataProvider invalidPasswords
     */
    public function test_room_passwords_are_validated_on_creation(mixed $password): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/games', [
            'bet_amount'  => 500,
            'max_players' => 4,
            'password'    => $password,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(0, GameRoom::count());
    }

    /**
     * A short room password only holds up because guessing is rationed. Without
     * this the join route would allow 120 tries a minute.
     */
    public function test_password_guessing_is_throttled(): void
    {
        $room = $this->createRoom(User::factory()->create(), 'letmein');

        Sanctum::actingAs(User::factory()->create());

        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->postJson("/api/games/{$room->code}/join", ['password' => "guess{$i}"])
                ->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'Repeated password attempts must be throttled.');
        $this->assertSame(10, count(array_filter($statuses, fn ($s) => $s === 403)));
    }

    /** bcrypt reads 72 bytes; accepting more would check a different string. */
    public function test_an_oversized_password_attempt_is_rejected_not_truncated(): void
    {
        $room = $this->createRoom(User::factory()->create(), str_repeat('a', 72));

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/games/{$room->code}/join", ['password' => str_repeat('a', 200)])
            ->assertForbidden();
    }
}
