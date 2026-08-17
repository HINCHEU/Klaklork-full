<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\GameRoom;
use App\Models\User;
use App\Events\BetPlaced;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BetController extends Controller
{
    /** POST /api/games/{code}/bets */
    public function store(Request $request, string $code)
    {
        $room = GameRoom::findByCodeOrFail($code);

        $data = $request->validate([
            'animal_slot' => 'required|integer|between:1,6',
        ]);

        $user       = $request->user();
        $betAmount  = $room->bet_amount;

        // Check player is in room
        if (! $room->hasPlayer($user->id)) {
            return response()->json(['message' => 'You are not in this room.'], 403);
        }

        // The host banks the round — they pay the winners and collect the losses,
        // so they can't also bet against themselves.
        if ($room->host_user_id === $user->id) {
            return response()->json(['message' => 'You are the banker — you cannot place bets in your own room.'], 403);
        }

        // Phase and affordability are both decided under lock. Checked outside
        // the transaction they are only advisory: two simultaneous bets would
        // each see the full balance and both go through, letting a player stake
        // money they do not have — and a bet could land after the host spun.
        //
        // Lock order is room then player, matching the settlement, so the two
        // paths queue behind each other instead of deadlocking.
        $outcome = DB::transaction(function () use ($room, $user, $data, $betAmount) {
            $lockedRoom = GameRoom::whereKey($room->id)->lockForUpdate()->first();

            if (! $lockedRoom || $lockedRoom->status !== 'betting') {
                return ['error' => ['message' => 'Betting is not open yet.'], 'status' => 422];
            }

            $player = User::whereKey($user->id)->lockForUpdate()->first();

            if ($player->balance < $betAmount) {
                return ['error' => ['message' => 'Insufficient balance.'], 'status' => 422];
            }

            $player->decrement('balance', $betAmount);

            // Upsert: if same slot bet exists, add to it
            $existing = Bet::where('game_room_id', $room->id)
                ->where('user_id', $user->id)
                ->where('animal_slot', $data['animal_slot'])
                ->first();

            if ($existing) {
                $existing->increment('amount', $betAmount);
                $bet = $existing->fresh();
            } else {
                $bet = Bet::create([
                    'game_room_id' => $room->id,
                    'user_id'      => $user->id,
                    'animal_slot'  => $data['animal_slot'],
                    'amount'       => $betAmount,
                ]);
            }

            return ['bet' => $bet, 'balance' => $player->fresh()->balance];
        });

        if (isset($outcome['error'])) {
            return response()->json($outcome['error'], $outcome['status']);
        }

        $bet = $outcome['bet'];

        try { broadcast(new BetPlaced($room, $user, $bet))->toOthers(); } catch (\Throwable) {}

        return response()->json([
            'bet'     => $bet,
            'balance' => $outcome['balance'],
        ], 201);
    }

    /** GET /api/games/{code}/bets — every bet in a room the caller is seated in */
    public function index(Request $request, string $code)
    {
        $room = GameRoom::findByCodeOrFail($code);

        // Who staked what is table information. Holding the room code is not a
        // seat at the table, so outsiders do not get to read the board.
        if (! $room->hasPlayer($request->user()->id)) {
            return response()->json(['message' => 'You are not in this room.'], 403);
        }

        $bets = $room->bets()->with('user:id,name')->get();

        return response()->json($bets);
    }
}
