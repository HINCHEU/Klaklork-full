<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameRoom;
use App\Models\User;
use App\Events\PlayerJoined;
use App\Events\PlayerLeft;
use App\Events\SpinStarted;
use App\Events\SpinResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameRoomController extends Controller
{
    /** GET /api/games — list joinable rooms (any phase, as long as someone is in them) */
    public function index()
    {
        $rooms = GameRoom::with('host:id,name')
            ->withCount('players')
            ->has('players')
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
        $room = GameRoom::findByCodeOrFail($code)
            ->load(['host:id,name', 'players:id,name,balance', 'bets.user:id,name'])
            ->loadCount('players');

        return response()->json($room);
    }

    /** POST /api/games/{code}/join */
    public function join(Request $request, string $code)
    {
        $room   = GameRoom::findByCodeOrFail($code);
        $userId = $request->user()->id;

        // Already a member — this is a reconnect (tab closed, refresh, invite link
        // re-opened). Let them back in whatever phase the room is currently in.
        if ($room->players()->where('user_id', $userId)->exists()) {
            return response()->json($room->load('host:id,name', 'players:id,name,balance'));
        }

        if ($room->players()->count() >= $room->max_players) {
            return response()->json(['message' => 'Room is full.'], 422);
        }

        $room->players()->attach($userId, ['joined_at' => now()]);

        try { broadcast(new PlayerJoined($room, $request->user()))->toOthers(); } catch (\Throwable) {}

        return response()->json($room->load('host:id,name', 'players:id,name,balance'));
    }

    /** POST /api/games/{code}/leave */
    public function leave(Request $request, string $code)
    {
        $room   = GameRoom::findByCodeOrFail($code);
        $userId = $request->user()->id;

        $room->players()->detach($userId);

        // Last one out closes the room for good
        if ($room->players()->count() === 0) {
            $room->delete();
            return response()->json(['message' => 'Room closed.']);
        }

        // Host left but players remain — hand the room to the next player
        if ($room->host_user_id === $userId) {
            $room->update(['host_user_id' => $room->players()->first()->id]);
        }

        try { broadcast(new PlayerLeft($room->fresh(), $request->user()))->toOthers(); } catch (\Throwable) {}

        return response()->json(['message' => 'Left room.']);
    }

    /** POST /api/games/{code}/open-betting — host opens betting */
    public function openBetting(Request $request, string $code)
    {
        $room = GameRoom::findByCodeOrFail($code);

        if ($room->host_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the host can start betting.'], 403);
        }

        if (! in_array($room->status, ['waiting', 'finished'])) {
            return response()->json(['message' => 'Cannot open betting from the current state.'], 422);
        }

        // Clear bets from the previous round
        $room->bets()->delete();
        $room->update(['status' => 'betting', 'result' => null]);

        try { broadcast(new \App\Events\BettingOpened($room)); } catch (\Throwable) {}

        return response()->json($room);
    }

    /** POST /api/games/{code}/spin — host triggers spin */
    public function spin(Request $request, string $code)
    {
        $room = GameRoom::findByCodeOrFail($code);

        if ($room->host_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the host can spin.'], 403);
        }

        if ($room->status !== 'betting') {
            return response()->json(['message' => 'Betting phase not active.'], 422);
        }

        $room->update(['status' => 'spinning']);
        try { broadcast(new SpinStarted($room)); } catch (\Throwable) {}

        return response()->json($room);
    }

    /** POST /api/games/{code}/stop — host stops spin and resolves results */
    public function stop(Request $request, string $code)
    {
        $room = GameRoom::findByCodeOrFail($code);

        if ($room->host_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the host can stop.'], 403);
        }

        if ($room->status !== 'spinning') {
            return response()->json(['message' => 'Not spinning.'], 422);
        }

        // Generate 3 random results (1–6), repeats allowed (authentic Kla Klouk rules)
        $slots = [
            random_int(1, 6),
            random_int(1, 6),
            random_int(1, 6),
        ];

        $room->update(['status' => 'finished', 'result' => $slots]);

        // Move money between the host (banker) and the players
        $settlement = $this->settleRound($room, $slots);

        // Broadcast results (with updated bets and user info)
        try { broadcast(new SpinResult($room->fresh(['bets.user:id,name']), $settlement)); } catch (\Throwable) {}

        return response()->json([
            'room'       => $room->fresh(['bets.user:id,name']),
            'settlement' => $settlement,
        ]);
    }

    /**
     * Settle a resolved round between the host (the banker) and every player.
     *
     * Every riel a player wins is paid by the host, and every riel a player
     * loses is collected by the host — the round is zero-sum:
     *
     *   - Symbol appears 1× → player gets stake back + 1× profit, host pays 1× stake
     *   - Symbol appears 2× → player gets stake back + 2× profit, host pays 2× stake
     *   - Symbol appears 3× → player gets stake back + 3× profit, host pays 3× stake
     *   - Symbol not present → the player's stake goes to the host
     *
     * The stake leaves the player's balance when the bet is placed, so a losing
     * stake only has to be credited to the host here. The host's balance may go
     * negative — the banker is allowed to be in the red.
     *
     * @return array{host: array, players: array} per-account settlement summary
     */
    private function settleRound(GameRoom $room, array $slots): array
    {
        // Count occurrences of each slot number in the 3-symbol result
        $slotCounts = array_count_values($slots);

        return DB::transaction(function () use ($room, $slotCounts) {
            $host    = User::lockForUpdate()->find($room->host_user_id);
            $hostNet = 0;
            $nets    = [];   // user_id => net riel for this round
            $players = [];   // user_id => User

            foreach ($room->bets()->with('user')->get() as $bet) {
                $count = $slotCounts[$bet->animal_slot] ?? 0;

                if ($count > 0) {
                    // Player wins: stake returned plus `count`× profit, paid by the host
                    $profit = $bet->amount * $count;
                    $bet->user->increment('balance', $bet->amount + $profit);
                    $hostNet -= $profit;
                    $net = $profit;
                } else {
                    // Player loses: the stake they already paid moves to the host
                    $hostNet += $bet->amount;
                    $net = -$bet->amount;
                }

                $bet->update(['won_amount' => $net]);

                $nets[$bet->user_id]    = ($nets[$bet->user_id] ?? 0) + $net;
                $players[$bet->user_id] = $bet->user;
            }

            if ($hostNet !== 0) {
                $host->increment('balance', $hostNet);   // negative = the banker pays out
            }

            return [
                'host' => [
                    'id'      => $host->id,
                    'name'    => $host->name,
                    'net'     => $hostNet,
                    'balance' => $host->fresh()->balance,
                ],
                'players' => collect($nets)->map(fn ($net, $userId) => [
                    'id'      => $userId,
                    'name'    => $players[$userId]->name,
                    'net'     => $net,
                    'balance' => $players[$userId]->fresh()->balance,
                ])->values()->all(),
            ];
        });
    }
}
