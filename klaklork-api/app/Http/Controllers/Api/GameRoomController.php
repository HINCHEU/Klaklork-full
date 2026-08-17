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
use Illuminate\Support\Facades\Hash;

class GameRoomController extends Controller
{
    /**
     * bcrypt only reads the first 72 bytes of a password, so anything longer is
     * silently truncated. Capping at the same point keeps what is accepted and
     * what is actually checked identical, and stops a huge string being hashed.
     */
    private const MAX_PASSWORD_BYTES = 72;

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
            // Omit it for an open room. A short minimum still matters: this is
            // guessable-by-strangers territory, and the join throttle is what
            // makes a 4-character password survive contact with a script.
            'password'    => ['nullable', 'string', 'min:4', 'max:'.self::MAX_PASSWORD_BYTES],
        ]);

        $room = GameRoom::create([
            'code'         => GameRoom::generateCode(),
            'host_user_id' => $request->user()->id,
            'bet_amount'   => $data['bet_amount'],
            'max_players'  => $data['max_players'],
            // The 'hashed' cast turns this into a bcrypt hash on the way in.
            'password'     => $data['password'] ?? null,
            'status'       => 'waiting',
        ]);

        // Host automatically joins
        $room->players()->attach($request->user()->id, ['joined_at' => now()]);

        return response()->json($room->load('host:id,name', 'players:id,name,balance'), 201);
    }

    /**
     * GET /api/games/{code} — room info.
     *
     * Non-members get a preview only. An invite link and the lobby's "resume"
     * card both have to read a room before the player is seated in it, so this
     * endpoint stays reachable — but balances and bets are table stakes, and
     * they are withheld until the caller is actually at the table.
     */
    public function show(Request $request, string $code)
    {
        $room = GameRoom::findByCodeOrFail($code);

        if (! $room->hasPlayer($request->user()->id)) {
            return response()->json($this->preview($room));
        }

        $room->load(['host:id,name', 'players:id,name,balance', 'bets.user:id,name'])
            ->loadCount('players');

        return response()->json($room);
    }

    /**
     * What an outsider holding the room code may see: enough to render the join
     * screen, nothing about anyone's money.
     *
     * Player ids and names are included because the client uses them to tell
     * whether it still needs to join; balances and bets are not.
     */
    private function preview(GameRoom $room): array
    {
        $room->load('host:id,name', 'players:id,name')->loadCount('players');

        return [
            'id'            => $room->id,
            'code'          => $room->code,
            'status'        => $room->status,
            'bet_amount'    => $room->bet_amount,
            'max_players'   => $room->max_players,
            // Whether a password will be asked for. The hash itself is hidden
            // on the model and never reaches this array.
            'is_private'    => $room->is_private,
            'host_user_id'  => $room->host_user_id,
            'host'          => $room->host,
            'players'       => $room->players,
            'players_count' => $room->players_count,
            'bets'          => [],
            'result'        => null,
        ];
    }

    /** POST /api/games/{code}/join */
    public function join(Request $request, string $code)
    {
        $room   = GameRoom::findByCodeOrFail($code);
        $userId = $request->user()->id;

        // Already a member — this is a reconnect (tab closed, refresh, invite link
        // re-opened). Let them back in whatever phase the room is currently in.
        //
        // This stays ahead of the password check on purpose: the lock is for
        // getting a seat at the table, not for keeping it. A player who is
        // already seated must not be shut out by a refresh, and the host — who
        // is attached at creation — never has to type their own password.
        if ($room->hasPlayer($userId)) {
            return response()->json($room->load('host:id,name', 'players:id,name,balance'));
        }

        if ($room->is_private) {
            $password = $request->input('password');

            // Missing and wrong both answer 403 with the same flag, because the
            // client makes the same decision either way: show the password box.
            // Only the wording differs. Hash::check is constant-time, so a wrong
            // password cannot be narrowed down by how long the answer takes.
            $ok = is_string($password)
                && strlen($password) <= self::MAX_PASSWORD_BYTES
                && Hash::check($password, $room->password);

            if (! $ok) {
                return response()->json([
                    'message'           => filled($password) ? 'Wrong password.' : 'This room needs a password.',
                    'password_required' => true,
                    // The client shows this prompt in the player's own language,
                    // so it needs the reason as a value rather than as prose.
                    'reason'            => filled($password) ? 'invalid' : 'missing',
                ], 403);
            }
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

        // You can only remove yourself, and only from a room you are actually in
        // — otherwise any caller could announce a stranger's departure.
        if (! $room->hasPlayer($userId)) {
            return response()->json(['message' => 'You are not in this room.'], 403);
        }

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

        $opened = DB::transaction(function () use ($room) {
            // Claim the transition first. Betting may only open from a settled
            // room, so wiping the previous round's bets can never race with the
            // settlement that is paying them out.
            $claimed = GameRoom::whereKey($room->id)
                ->whereIn('status', ['waiting', 'finished'])
                ->update(['status' => 'betting', 'result' => null]);

            if ($claimed === 0) {
                return false;
            }

            // Clear bets from the previous round
            $room->bets()->delete();

            return true;
        });

        if (! $opened) {
            return response()->json(['message' => 'Cannot open betting from the current state.'], 422);
        }

        $room->refresh();

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

        // Closing betting is the moment stakes stop being accepted, so make the
        // transition itself the gate — a bet that has not committed by now loses
        // the race against it rather than slipping into a spinning round.
        $claimed = GameRoom::whereKey($room->id)
            ->where('status', 'betting')
            ->update(['status' => 'spinning']);

        if ($claimed === 0) {
            return response()->json(['message' => 'Betting phase not active.'], 422);
        }

        $room->refresh();

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

        // Generate 3 random results (1–6), repeats allowed (authentic Kla Klouk rules)
        $slots = [
            random_int(1, 6),
            random_int(1, 6),
            random_int(1, 6),
        ];

        $settlement = DB::transaction(function () use ($room, $slots) {
            // Claim the round before paying anyone. Reading the status and then
            // updating it leaves a gap where two stop requests both see
            // 'spinning' and settle the same bets twice, minting money out of
            // the banker. Only the update that actually flips the row wins.
            $claimed = GameRoom::whereKey($room->id)
                ->where('status', 'spinning')
                ->update(['status' => 'finished', 'result' => json_encode($slots)]);

            if ($claimed === 0) {
                return null;
            }

            // Move money between the host (banker) and the players
            return $this->settleRound($room, $slots);
        });

        if ($settlement === null) {
            return response()->json(['message' => 'Not spinning.'], 422);
        }

        $room->refresh();

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
     * Must run inside a transaction that has already claimed the round, so a
     * round can never be settled twice.
     *
     * @return array{host: array, players: array} per-account settlement summary
     */
    private function settleRound(GameRoom $room, array $slots): array
    {
        // Count occurrences of each slot number in the 3-symbol result
        $slotCounts = array_count_values($slots);

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
    }
}
