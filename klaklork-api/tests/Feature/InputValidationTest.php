<?php

namespace Tests\Feature;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InputValidationTest extends TestCase
{
    use RefreshDatabase;

    /** Room codes are the only untrusted value that reaches a lookup. */
    public static function malformedCodes(): array
    {
        return [
            'too long'        => [str_repeat('A', 500)],
            'too short'       => ['ABC'],
            'sql fragment'    => ["A' OR '1'='1"],
            'script tag'      => ['<script>'],
            'path traversal'  => ['../user'],
            'null byte'       => ["ABC\0DE"],
            'excluded letter' => ['ABCDEI'],
            'excluded digit'  => ['ABCDE0'],
            'fullwidth'       => ['ＡＢＣＤＥＦ'],
            'whitespace'      => ['ABC DE'],
        ];
    }

    /**
     * @dataProvider malformedCodes
     */
    public function test_a_malformed_room_code_is_refused_without_a_query(string $code): void
    {
        Sanctum::actingAs(User::factory()->create());

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson('/api/games/'.rawurlencode($code))->assertNotFound();

        $this->assertSame(0, $queries, 'A value that cannot be a room code must not reach the database.');
    }

    public function test_a_well_formed_code_still_resolves_in_either_case(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::create([
            'code'         => 'ABC234',
            'host_user_id' => $host->id,
            'bet_amount'   => 500,
            'max_players'  => 10,
            'status'       => 'waiting',
        ]);
        $room->players()->attach($host->id, ['joined_at' => now()]);

        Sanctum::actingAs($host);

        $this->getJson('/api/games/abc234')->assertOk()->assertJsonPath('code', 'ABC234');
        $this->getJson('/api/games/ABC234')->assertOk()->assertJsonPath('code', 'ABC234');
    }

    /**
     * The route constraint and the generator have to describe the same alphabet.
     * If one is edited without the other, real codes start 404ing.
     */
    public function test_generated_codes_always_satisfy_the_route_constraint(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $code = GameRoom::generateCode();

            $this->assertTrue(
                GameRoom::isValidCode($code),
                "Generated code {$code} does not match CODE_PATTERN."
            );
            $this->assertSame(GameRoom::CODE_LENGTH, strlen($code));
        }
    }

    /** The channel name is client-supplied and never passes through the router. */
    public function test_a_malformed_channel_code_is_refused(): void
    {
        $callback = Broadcast::getChannels()['game.{code}'];
        $user     = User::factory()->create();

        foreach (["' OR 1=1", str_repeat('A', 500), '../../etc', 'ABC'] as $code) {
            $this->assertFalse((bool) $callback($user, $code), "Channel accepted '{$code}'.");
        }
    }

    public static function rejectedNames(): array
    {
        return [
            'script tag'     => ['<script>alert(1)</script>'],
            'html'           => ['<b>bold</b>'],
            'quote'          => ['O"Brien'],
            'sql fragment'   => ["'; DROP TABLE users; --"],
            'backslash'      => ['a\\b'],
            'slash'          => ['a/b'],
            'ampersand'      => ['Tom & Jerry'],
            'too short'      => ['A'],
            'too long'       => [str_repeat('a', 21)],
            'leading mark'   => ['́abc'],
            'zero width'     => ["ab\u{200B}cd"],
            'rtl override'   => ["ab\u{202E}cd"],
            'empty'          => [''],
        ];
    }

    /**
     * @dataProvider rejectedNames
     */
    public function test_display_names_outside_the_allowlist_are_refused(string $name): void
    {
        $this->postJson('/api/guest', ['name' => $name])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(0, User::count());
    }

    public static function acceptedNames(): array
    {
        return [
            'latin'        => ['Sokha', 'Sokha'],
            'khmer'        => ['ម្ចាស់ការ', 'ម្ចាស់ការ'],
            'digits'       => ['Player7', 'Player7'],
            'punctuation'  => ['a_b.c-d', 'a_b.c-d'],
            'inner spaces' => ['Sok  Dara', 'Sok Dara'],
            'padded'       => ['  Sokha  ', 'Sokha'],
            // Stray control and exotic whitespace is normalised to a single
            // space rather than refused: nothing but a plain space can reach
            // storage either way, and a pasted name is not worth an error.
            'newline'      => ["Sok\nDara", 'Sok Dara'],
            'tab'          => ["Sok\tDara", 'Sok Dara'],
            'nbsp'         => ["Sok\u{00A0}Dara", 'Sok Dara'],
        ];
    }

    /**
     * @dataProvider acceptedNames
     */
    public function test_valid_names_are_accepted_and_normalised(string $input, string $stored): void
    {
        $this->postJson('/api/guest', ['name' => $input])
            ->assertCreated()
            ->assertJsonPath('user.name', $stored);
    }

    /** The rename path shares the rule, so it must reject exactly the same input. */
    public function test_rename_enforces_the_same_rule_as_entry(): void
    {
        $user = User::factory()->create(['name' => 'Sokha']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/user', ['name' => '<script>x</script>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame('Sokha', $user->fresh()->name);

        $this->patchJson('/api/user', ['name' => 'Dara  Chan'])
            ->assertOk()
            ->assertJsonPath('name', 'Dara Chan');
    }

    public static function invalidRoomSettings(): array
    {
        return [
            'bet too low'     => [['bet_amount' => 99,      'max_players' => 4]],
            'bet too high'    => [['bet_amount' => 100001,  'max_players' => 4]],
            'bet not integer' => [['bet_amount' => 'lots',  'max_players' => 4]],
            'bet fractional'  => [['bet_amount' => 500.5,   'max_players' => 4]],
            'bet array'       => [['bet_amount' => [500],   'max_players' => 4]],
            'players too few' => [['bet_amount' => 500,     'max_players' => 1]],
            'players too many'=> [['bet_amount' => 500,     'max_players' => 21]],
            'players missing' => [['bet_amount' => 500]],
            'bet missing'     => [['max_players' => 4]],
        ];
    }

    /**
     * @dataProvider invalidRoomSettings
     */
    public function test_room_settings_are_strictly_typed(array $payload): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/games', $payload)->assertStatus(422);

        $this->assertSame(0, GameRoom::count());
    }

    public static function invalidSlots(): array
    {
        return [
            'zero'       => [0],
            'seven'      => [7],
            'negative'   => [-1],
            'fractional' => [2.5],
            'string'     => ['tiger'],
            'array'      => [[1]],
            'null'       => [null],
        ];
    }

    /**
     * @dataProvider invalidSlots
     */
    public function test_bet_slots_outside_the_board_are_refused(mixed $slot): void
    {
        $host   = User::factory()->create();
        $player = User::factory()->create(['balance' => 10000]);

        $room = GameRoom::create([
            'code'         => GameRoom::generateCode(),
            'host_user_id' => $host->id,
            'bet_amount'   => 500,
            'max_players'  => 10,
            'status'       => 'betting',
        ]);
        $room->players()->attach($host->id, ['joined_at' => now()]);
        $room->players()->attach($player->id, ['joined_at' => now()]);

        Sanctum::actingAs($player);

        $this->postJson("/api/games/{$room->code}/bets", ['animal_slot' => $slot])
            ->assertStatus(422);

        $this->assertSame(0, $room->bets()->count());
        $this->assertSame(10000, $player->fresh()->balance);
    }
}
