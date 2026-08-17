<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimits();
    }

    /**
     * Abuse limits for every route.
     *
     * Each limiter is keyed twice: once by player, and once by address. The
     * per-player key is the fair-use limit. The per-address key is what stops a
     * script from stepping around it — guest accounts are free to mint, so
     * without an address ceiling an attacker would simply hold a hundred tokens
     * and get a hundred private buckets. Both have to pass.
     *
     * These are deliberately well clear of what the real client does: the room
     * view polls a room 12×/min and the lobby polls the room list 6×/min, so a
     * player on a flaky connection is never the one who trips a limit.
     */
    private function configureRateLimits(): void
    {
        // Account creation — the only unauthenticated route, and every call
        // mints a player holding a fresh 10,000 riel. This is the one that has
        // to be held tightest.
        //
        // It is keyed by address alone, which is also why it is not tighter:
        // friends playing together are very often behind one NAT, so the limit
        // has to fit a party arriving at once while still refusing bulk minting.
        //
        // The two windows are keyed apart on purpose. Laravel buckets a limit
        // by md5(limiter name + key), so giving both the bare address would put
        // the burst and the hourly cap in one bucket carrying one expiry — the
        // minute timer would keep clearing it and the hourly ceiling would never
        // once bite, leaving this at 8/min forever (480/hour).
        RateLimiter::for('guest-entry', fn (Request $request) => [
            Limit::perMinute(8)->by('burst:'.$request->ip()),
            Limit::perHour(40)->by('hourly:'.$request->ip()),
        ]);

        // Reads a scraper would go after: the public lobby listing and room
        // detail. The ceiling is per-address so a pile of minted tokens does not
        // multiply how fast one machine can harvest rooms.
        RateLimiter::for('read', fn (Request $request) => [
            Limit::perMinute(90)->by($this->playerKey($request)),
            Limit::perMinute(300)->by('addr:'.$request->ip()),
        ]);

        // State changes: joining, leaving, staking, and the host's round
        // controls. Generous enough for an excited player clicking a chip
        // repeatedly, bounded enough that a script cannot drive a table.
        RateLimiter::for('write', fn (Request $request) => [
            Limit::perMinute(120)->by($this->playerKey($request)),
            Limit::perMinute(400)->by('addr:'.$request->ip()),
        ]);

        // Joining is where a room password is guessed, so it cannot sit under
        // the general write budget of 120/min. This is the limit that turns a
        // short room password into something a script cannot walk through:
        // a few tries a minute, per player and per address.
        //
        // It still has to fit honest use — a reconnect calls join once, and a
        // player fumbling their password a few times should not be locked out.
        RateLimiter::for('join-room', fn (Request $request) => [
            Limit::perMinute(10)->by($this->playerKey($request)),
            Limit::perMinute(30)->by('addr:'.$request->ip()),
        ]);

        // Rooms outlive the request that made them, so room spam is litter that
        // sticks around in the lobby.
        RateLimiter::for('create-room', fn (Request $request) => [
            Limit::perHour(15)->by($this->playerKey($request)),
            Limit::perHour(40)->by('addr:'.$request->ip()),
        ]);

        // One handshake per channel subscription — a client that needs more than
        // this is reconnecting in a loop.
        RateLimiter::for('broadcasting', fn (Request $request) => [
            Limit::perMinute(30)->by($this->playerKey($request)),
            Limit::perMinute(120)->by('addr:'.$request->ip()),
        ]);

        // Catch-all ceiling applied to the whole authenticated surface, so a
        // route that gains no specific limiter is still never unbounded.
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute(150)->by($this->playerKey($request)),
            Limit::perMinute(600)->by('addr:'.$request->ip()),
        ]);
    }

    /**
     * Bucket key for the caller: the authenticated player where there is one,
     * otherwise the address they came from.
     *
     * The token is resolved here rather than read off the request, because the
     * throttle deliberately runs before the auth middleware (see the priority
     * list in bootstrap/app.php) so that failed authentication is rate limited
     * too. At that point nothing has populated the request's user, and the
     * default guard is session based — asking it would report nobody for a
     * bearer-token request and collapse every player onto the address bucket.
     * The guard memoizes, so this costs one token lookup per request.
     */
    private function playerKey(Request $request): string
    {
        $user = $request->user() ?? Auth::guard('sanctum')->user();

        // Prefixed distinctly from the 'addr:' ceiling so that an anonymous
        // caller's two limits stay two buckets — sharing a key would silently
        // merge them and count every request twice.
        return $user
            ? 'fair:user:'.$user->id
            : 'fair:ip:'.$request->ip();
    }
}
