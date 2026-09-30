<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Every visit to the name screen creates a guest, and a guest can only be
 * resumed from the one browser holding its token. Abandoned ones are deleted
 * after User::GUEST_RETENTION_DAYS — but never anyone still at a table, and
 * never an account with real credentials.
 */
class GuestPruneTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} the guest and their plain-text token */
    private function guest(): array
    {
        $user = User::factory()->guest()->create();

        return [$user, $user->createToken('guest')->plainTextToken];
    }

    private function prune(): void
    {
        $this->artisan('model:prune', ['--model' => [User::class]])->assertSuccessful();
    }

    private function pastRetention(): void
    {
        $this->travel(User::GUEST_RETENTION_DAYS + 1)->days();
    }

    private function room(User $host): GameRoom
    {
        $room = GameRoom::create([
            'code'           => GameRoom::generateCode(),
            'host_user_id'   => $host->id,
            'bet_amount'     => 500,
            'max_players'    => 10,
            'status'         => 'waiting',
            'last_active_at' => now(),
        ]);

        $room->players()->attach($host->id, ['joined_at' => now(), 'last_seen_at' => now()]);

        return $room;
    }

    public function test_an_abandoned_guest_is_deleted_with_their_tokens(): void
    {
        [$guest] = $this->guest();

        $this->pastRetention();
        $this->prune();

        $this->assertModelMissing($guest);
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $guest->id)->count());
    }

    public function test_a_guest_who_played_within_the_window_is_kept(): void
    {
        [$guest, $token] = $this->guest();

        $this->travel(User::GUEST_RETENTION_DAYS - 1)->days();
        $this->withToken($token)->getJson('/api/user')->assertOk();

        $this->travel(2)->days();   // well past 30 days since they joined
        $this->prune();

        $this->assertModelExists($guest);
    }

    public function test_a_guest_whose_row_changed_within_the_window_is_kept(): void
    {
        [$guest] = $this->guest();

        $this->travel(User::GUEST_RETENTION_DAYS - 1)->days();
        $guest->update(['name' => 'Renamed']);

        $this->travel(2)->days();
        $this->prune();

        $this->assertModelExists($guest);
    }

    public function test_a_guest_just_inside_the_window_is_kept(): void
    {
        [$guest] = $this->guest();

        $this->travel(User::GUEST_RETENTION_DAYS - 1)->days();
        $this->prune();

        $this->assertModelExists($guest);
    }

    public function test_an_account_with_a_password_is_never_pruned(): void
    {
        $member = User::factory()->create();   // pre-guest account, has credentials

        $this->pastRetention();
        $this->prune();

        $this->assertModelExists($member);
    }

    public function test_a_guest_still_seated_in_a_room_is_kept(): void
    {
        [$host] = $this->guest();
        [$seated] = $this->guest();
        $this->room($host)->players()->attach($seated->id, ['joined_at' => now()]);

        $this->pastRetention();
        $this->prune();

        $this->assertModelExists($seated);
    }

    public function test_a_guest_hosting_a_room_is_kept_and_the_room_survives(): void
    {
        [$host] = $this->guest();
        $room = $this->room($host);
        $room->players()->detach($host->id);   // host but no seat: still must not cascade

        $this->pastRetention();
        $this->prune();

        $this->assertModelExists($host);
        $this->assertModelExists($room);
    }

    public function test_a_guest_with_a_bet_on_a_table_is_kept(): void
    {
        [$host] = $this->guest();
        [$bettor] = $this->guest();
        $room = $this->room($host);

        Bet::create(['game_room_id' => $room->id, 'user_id' => $bettor->id, 'animal_slot' => 1, 'amount' => 500]);

        $this->pastRetention();
        $this->prune();

        $this->assertModelExists($bettor);
    }

    public function test_a_pruned_guests_old_token_no_longer_signs_in(): void
    {
        [, $token] = $this->guest();

        $this->pastRetention();
        $this->prune();

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_guest_pruning_is_scheduled_daily_with_a_short_lived_lock(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'model:prune'));

        $this->assertNotNull($event);
        $this->assertStringContainsString('User', $event->command);
        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertLessThanOrEqual(60, $event->expiresAt);
    }
}
