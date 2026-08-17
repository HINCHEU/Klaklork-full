<?php

namespace Tests\Feature;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** Resolve a limiter and hand back the Limit objects it produces. */
    private function limitsFor(string $limiter, ?User $user = null): array
    {
        $request = Request::create('/api/games', 'GET');
        $request->server->set('REMOTE_ADDR', '203.0.113.9');

        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return (array) (RateLimiter::limiter($limiter))($request);
    }

    public function test_account_creation_is_capped_per_address(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->postJson('/api/guest', ['name' => "Player{$i}"])->assertCreated();
        }

        $this->postJson('/api/guest', ['name' => 'Player9'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // The 9th account was refused, not quietly created.
        $this->assertSame(8, User::count());
    }

    /**
     * The burst limit alone is not the protection — 8/minute sustained is 480
     * accounts an hour. The hourly ceiling is what actually bounds minting, so
     * it has to survive the burst window resetting underneath it.
     */
    public function test_account_creation_has_an_hourly_ceiling_not_just_a_burst_limit(): void
    {
        $created = 0;

        // Six minutes of maximum burst would be 48 accounts if only the
        // per-minute limit applied.
        for ($minute = 0; $minute < 6; $minute++) {
            for ($i = 0; $i < 8; $i++) {
                if ($this->postJson('/api/guest', ['name' => "Player{$created}"])->getStatusCode() === 201) {
                    $created++;
                }
            }

            $this->travel(61)->seconds();
        }

        $this->assertSame(40, $created, 'The hourly ceiling must outlast the burst window.');
        $this->assertSame(40, User::count());
    }

    /**
     * The anti-bypass property. Guest accounts are free, so a per-player limit
     * alone would be worthless — a script would hold many tokens and get many
     * private buckets. Every limiter must also pin one bucket to the address.
     */
    public function test_every_authenticated_limiter_also_has_an_address_ceiling(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();

        foreach (['read', 'write', 'create-room', 'broadcasting', 'api'] as $limiter) {
            $forAlice = $this->limitsFor($limiter, $alice);
            $forBob   = $this->limitsFor($limiter, $bob);

            $this->assertCount(2, $forAlice, "{$limiter} must limit by player and by address.");

            // The player bucket separates them...
            $this->assertNotSame(
                $forAlice[0]->key,
                $forBob[0]->key,
                "{$limiter} must give each player their own fair-use bucket."
            );

            // ...but the address bucket is shared, so extra tokens buy nothing.
            $this->assertSame(
                $forAlice[1]->key,
                $forBob[1]->key,
                "{$limiter} must share one ceiling across every token from an address."
            );
        }
    }

    /**
     * The same two properties as above, but exercised through real requests —
     * this is what proves the limiter can still identify a player now that the
     * throttle runs ahead of the auth middleware.
     */
    public function test_players_have_separate_budgets_but_share_the_address_ceiling(): void
    {
        $room = ['bet_amount' => 500, 'max_players' => 4];

        // Alice spends her whole per-player room budget (15/hour).
        Sanctum::actingAs(User::factory()->create());
        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/api/games', $room)->assertCreated();
        }
        $this->postJson('/api/games', $room)->assertStatus(429);

        // Bob is untouched by that — fair use is per player, so one heavy
        // player never starves the rest of the table.
        Sanctum::actingAs(User::factory()->create());
        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/api/games', $room)->assertCreated();
        }

        // Carol shares the address ceiling (40/hour), so minting a third token
        // buys the remaining 10 rooms and nothing more.
        Sanctum::actingAs(User::factory()->create());
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/games', $room)->assertCreated();
        }
        $this->postJson('/api/games', $room)->assertStatus(429);

        $this->assertSame(40, GameRoom::count(), 'The address ceiling is the real cap.');
    }

    /** A junk bearer token must not be a way around the limits. */
    public function test_failed_authentication_is_rate_limited_too(): void
    {
        $statuses = [];

        for ($i = 0; $i < 95; $i++) {
            $statuses[] = $this->withHeader('Authorization', 'Bearer not-a-real-token')
                ->getJson('/api/games')
                ->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'Invalid tokens must be throttled, not just rejected.');
        $this->assertSame(401, $statuses[0], 'A first bad token is still an auth failure, not a throttle.');
    }

    public function test_unauthenticated_callers_fall_back_to_an_address_bucket(): void
    {
        $limits = $this->limitsFor('read');

        $this->assertStringContainsString('203.0.113.9', $limits[0]->key);
    }

    /** Rate limiting is worthless if a route quietly ships without it. */
    public function test_every_api_route_carries_a_throttle(): void
    {
        $unthrottled = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $hasThrottle = collect($route->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));

            if (! $hasThrottle) {
                $unthrottled[] = $route->uri();
            }
        }

        $this->assertSame([], $unthrottled, 'These API routes have no rate limit.');
    }

    public function test_a_throttled_player_is_told_how_long_to_wait(): void
    {
        $host = User::factory()->create();

        Sanctum::actingAs($host);

        // create-room is the tightest authenticated limiter (15/hour).
        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/api/games', ['bet_amount' => 500, 'max_players' => 4])
                ->assertCreated();
        }

        $response = $this->postJson('/api/games', ['bet_amount' => 500, 'max_players' => 4])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertSame(15, GameRoom::where('host_user_id', $host->id)->count());
        $this->assertNotEmpty($response->json('message'));
    }
}
