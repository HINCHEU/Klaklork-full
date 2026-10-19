<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    /**
     * A player counts as online if any of their tokens was used this recently.
     * last_used_at is stamped at most once a minute (App\Models\PersonalAccessToken)
     * and the client polls far more often (lobby every 10 s, room every 5 s), so
     * anyone with the game open stays inside the window and a closed tab drops out.
     */
    public const ONLINE_WINDOW_SECONDS = 120;

    /**
     * New players are turned away above this many online. The 2 vCPU server's
     * load test stayed error-free to ~1,080 players and locked up at ~1,200.
     */
    public const MAX_ONLINE = 900;

    /**
     * The landing page polls this for every visitor, so the count is computed
     * once per window and shared — the cost does not grow with the audience.
     */
    private const CACHE_SECONDS = 15;

    /** GET /api/stats/online — public: how many players are in the game right now */
    public function online()
    {
        return response()->json(['online' => self::onlineCount()]);
    }

    // shortcut: up to 15 s stale, so a rush can overshoot MAX_ONLINE a little; fine below the ~1,200 lock-up.
    public static function onlineCount(): int
    {
        return Cache::remember('stats:online', self::CACHE_SECONDS, fn () => DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('last_used_at', '>=', now()->subSeconds(self::ONLINE_WINDOW_SECONDS))
            ->distinct()
            ->count('tokenable_id'));
    }
}
