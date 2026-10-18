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
     * Sanctum stamps last_used_at on every request, and the client polls far
     * more often than this (lobby every 10 s, room every 5 s), so anyone with
     * the game open stays inside the window and a closed tab drops out of it.
     */
    public const ONLINE_WINDOW_SECONDS = 120;

    /**
     * The landing page polls this for every visitor, so the count is computed
     * once per window and shared — the cost does not grow with the audience.
     */
    private const CACHE_SECONDS = 15;

    /** GET /api/stats/online — public: how many players are in the game right now */
    public function online()
    {
        $online = Cache::remember('stats:online', self::CACHE_SECONDS, fn () => DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('last_used_at', '>=', now()->subSeconds(self::ONLINE_WINDOW_SECONDS))
            ->distinct()
            ->count('tokenable_id'));

        return response()->json(['online' => $online]);
    }
}
