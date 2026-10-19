<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StatsController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OnlineStatsTest extends TestCase
{
    use RefreshDatabase;

    private function playerLastSeen(?\DateTimeInterface $at): User
    {
        $user  = User::factory()->create();
        $token = $user->createToken('guest')->accessToken;
        $token->forceFill(['last_used_at' => $at])->save();

        return $user;
    }

    public function test_counts_each_recently_active_player_once_without_auth(): void
    {
        $twoTabs = $this->playerLastSeen(now()->subSeconds(10));
        $twoTabs->createToken('second-tab')->accessToken->forceFill(['last_used_at' => now()])->save();
        $this->playerLastSeen(now()->subSeconds(60));
        $this->playerLastSeen(now()->subMinutes(10));   // closed the tab long ago
        $this->playerLastSeen(null);                    // token never used

        Cache::forget('stats:online');

        $this->getJson('/api/stats/online')
            ->assertOk()
            ->assertExactJson(['online' => 2]);
    }

    public function test_new_players_are_turned_away_once_the_game_is_full(): void
    {
        Cache::put('stats:online', StatsController::MAX_ONLINE - 1, 60);
        $this->postJson('/api/guest', ['name' => 'LastSeat'])->assertCreated();

        Cache::put('stats:online', StatsController::MAX_ONLINE, 60);
        $this->postJson('/api/guest', ['name' => 'TooLate'])
            ->assertStatus(503)
            ->assertExactJson(['message' => 'max player']);

        $this->assertSame(0, User::where('name', 'TooLate')->count());
    }
}
