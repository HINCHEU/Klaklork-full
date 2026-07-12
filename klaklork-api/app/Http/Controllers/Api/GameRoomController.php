<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameRoom;
use App\Events\PlayerJoined;
use App\Events\SpinStarted;
use App\Events\SpinResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameRoomController extends Controller
{
    /** GET /api/games — list open rooms */
    public function index()
    {
        $rooms = GameRoom::with('host:id,name')
            ->withCount('players')
            ->whereIn('status', ['waiting', 'betting'])
            ->latest()
            ->get();

        return response()->json($rooms);
    }

    /** POST /api/games — create a room */
    public function store(Request $request)
    {
        $data = $request->validate([
            'bet_amount'  => 'required|integer|min:100|max:100000',
            'max_players' => 'required|integer|min:2|max:20',
        ]);

        $room = GameRoom::create([
            'code'         => GameRoom::generateCode(),
            'host_user_id' => $request->user()->id,
            'bet_amount'   => $data['bet_amount'],
            'max_players'  => $data['max_players'],
            'status'       => 'waiting',
        ]);

        // Host automatically joins
        $room->players()->attach($request->user()->id, ['joined_at' => now()]);

        return response()->json($room->load('host:id,name', 'players:id,name,balance'), 201);
    }

    /** GET /api/games/{code} — get room info */
    public function show(string $code)
    {
        $room = GameRoom::where('code', $code)
            ->with(['host:id,name', 'players:id,name,balance', 'bets.user:id,name'])
            ->withCount('players')
            ->firstOrFail();

        return response()->json($room);
    }

    /** POST /api/games/{code}/join */
    public function join(Request $request, string $code)
    {
        $room = GameRoom::where('code', $code)->firstOrFail();

        if ($room->status !== 'waiting') {
            return response()->json(['message' => 'Game has already started.'], 422);
        }

        if ($room->players()->count() >= $room->max_players) {
            return response()->json(['message' => 'Room is full.'], 422);
        }

        $userId = $request->user()->id;

        if ($room->players()->where('user_id', $userId)->exists()) {
            return response()->json($room->load('host:id,name', 'players:id,name,balance'));
        }

        $room->players()->attach($userId, ['joined_at' => now()]);

        broadcast(new PlayerJoined($room, $request->user()))->toOthers();

        return response()->json($room->load('host:id,name', 'players:id,name,balance'));
    }

    /** POST /api/games/{code}/spin — host triggers spin */
    public function spin(Request $request, string $code)
    {
        $room = GameRoom::where('code', $code)->firstOrFail();

        if ($room->host_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the host can spin.'], 403);
        }

        if ($room->status !== 'betting') {
            return response()->json(['message' => 'Betting phase not active.'], 422);
        }

        $room->update(['status' => 'spinning']);
        broadcast(new SpinStarted($room));

        return response()->json($room);
    }

    /** POST /api/games/{code}/stop — host stops spin */
    public function stop(Request $request, string $code)
    {
        $room = GameRoom::where('code', $code)->firstOrFail();

        if ($room->host_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the host can stop.'], 403);
        }

        if ($room->status !== 'spinning') {
            return response()->json(['message' => 'Not spinning.'], 422);
        }

        // Generate 3 random results (1-6, no repeat)
        $slots = collect(range(1, 6))->shuffle()->take(3)->values()->toArray();
        $room->update(['status' => 'finished', 'result' => $slots]);

        // Calculate payouts
        $this->calculatePayouts($room, $slots);

        broadcast(new SpinResult($room->fresh(['bets.user:id,name'])));

        return response()->json($room->fresh(['bets.user:id,name']));
    }

    /** POST /api/games/{code}/open-betting — host opens betting */
    public function openBetting(Request $request, string $code)
    {
        $room = GameRoom::where('code', $code)->firstOrFail();

        if ($room->host_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the host can start betting.'], 403);
        }

        if ($room->status !== 'waiting') {
            return response()->json(['message' => 'Room is not in waiting state.'], 422);
        }

        $room->update(['status' => 'betting']);

        broadcast(new \App\Events\BettingOpened($room));

        return response()->json($room);
    }

    private function calculatePayouts(GameRoom $room, array $slots): void
    {
        // Count how many times each slot appears in results
        $slotCounts = array_count_values($slots);

        DB::transaction(function () use ($room, $slotCounts) {
            foreach ($room->bets as $bet) {
                $slot  = $bet->animal_slot;
                $count = $slotCounts[$slot] ?? 0;

                $won = match ($count) {
                    1 => $bet->amount * 1,   // 1x win
                    2 => $bet->amount * 2,   // 2x win
                    3 => $bet->amount * 3,   // 3x win
                    default => -$bet->amount, // loss
                };

                $bet->update(['won_amount' => $won]);

                // Update user balance
                $bet->user->increment('balance', $won + ($count > 0 ? $bet->amount : 0));
            }
        });
    }
}
