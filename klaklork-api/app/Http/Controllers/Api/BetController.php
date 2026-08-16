<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\GameRoom;
use App\Events\BetPlaced;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BetController extends Controller
{
    /** POST /api/games/{code}/bets */
    public function store(Request $request, string $code)
    {
        $room = GameRoom::findByCodeOrFail($code);

        if ($room->status !== 'betting') {
            return response()->json(['message' => 'Betting is not open yet.'], 422);
        }

        $data = $request->validate([
            'animal_slot' => 'required|integer|between:1,6',
        ]);

        $user       = $request->user();
        $betAmount  = $room->bet_amount;

        // Check player is in room
        if (! $room->players()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'You are not in this room.'], 403);
        }

        // The host banks the round — they pay the winners and collect the losses,
        // so they can't also bet against themselves.
        if ($room->host_user_id === $user->id) {
            return response()->json(['message' => 'You are the banker — you cannot place bets in your own room.'], 403);
        }

        if ($user->balance < $betAmount) {
            return response()->json(['message' => 'Insufficient balance.'], 422);
        }

        $bet = DB::transaction(function () use ($room, $user, $data, $betAmount) {
            $user->decrement('balance', $betAmount);

            // Upsert: if same slot bet exists, add to it
            $existing = Bet::where('game_room_id', $room->id)
                ->where('user_id', $user->id)
                ->where('animal_slot', $data['animal_slot'])
                ->first();

            if ($existing) {
                $existing->increment('amount', $betAmount);
                return $existing->fresh();
            }

            return Bet::create([
                'game_room_id' => $room->id,
                'user_id'      => $user->id,
                'animal_slot'  => $data['animal_slot'],
                'amount'       => $betAmount,
            ]);
        });

        try { broadcast(new BetPlaced($room, $user, $bet))->toOthers(); } catch (\Throwable) {}

        return response()->json([
            'bet'     => $bet,
            'balance' => $user->fresh()->balance,
        ], 201);
    }

    /** GET /api/games/{code}/bets — get all bets for a room */
    public function index(string $code)
    {
        $room = GameRoom::findByCodeOrFail($code);
        $bets = $room->bets()->with('user:id,name')->get();

        return response()->json($bets);
    }
}
