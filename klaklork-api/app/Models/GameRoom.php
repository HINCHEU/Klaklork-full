<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GameRoom extends Model
{
    protected $fillable = [
        'code',
        'host_user_id',
        'status',
        'bet_amount',
        'max_players',
        'password',
        'result',
        'last_active_at',
    ];

    protected $casts = [
        'result' => 'array',
        'bet_amount' => 'integer',
        'max_players' => 'integer',
        'last_active_at' => 'datetime',
        // Hashes on assignment, so a plaintext room password never reaches a
        // column — and never gets logged by a query listener either.
        'password' => 'hashed',
    ];

    /**
     * The hash never leaves the server. Everything the client needs to know
     * about the lock is carried by the is_private flag below.
     */
    protected $hidden = [
        'password',
    ];

    protected $appends = [
        'is_private',
    ];

    /** Whether this room is locked. Safe to show anyone — it is not a secret. */
    public function getIsPrivateAttribute(): bool
    {
        return $this->password !== null;
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'game_players')
            ->withPivot('joined_at', 'last_seen_at')
            ->orderByPivot('joined_at');
    }

    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    /**
     * Is this user seated in the room?
     *
     * Membership is the authorization boundary for everything inside a room —
     * the roster with balances, the bets, and the realtime channel. A room code
     * is a 6-character invite, not a credential, so knowing one is never enough.
     */
    public function hasPlayer(?int $userId): bool
    {
        return $userId !== null
            && $this->players()->wherePivot('user_id', $userId)->exists();
    }

    /**
     * A room nobody seated in has touched for this long is closed by
     * rooms:close-idle. The client polls every few seconds while it is open, so
     * this is "every tab on the table has been closed for half an hour".
     */
    public const IDLE_MINUTES = 30;

    /**
     * A player whose tab has been silent this long has left the room, and is
     * removed by rooms:remove-absent-players exactly as if they had pressed
     * Leave — the host included, which hands the bank on.
     *
     * A browser cannot reliably tell the server that a tab closed (a refresh
     * looks the same), so absence is the signal. Browsers slow timers in
     * background tabs to about once a minute, so this sits comfortably above
     * that: a player who merely switched tabs keeps their seat.
     */
    public const AWAY_MINUTES = 3;

    /**
     * A player unseated for being away did not choose to leave — their phone
     * slept, or they switched apps. For this long they may take a seat in a
     * locked room again without retyping the password. Matches the idle
     * cleanup, so the pass never outlives an abandoned room's own grace period.
     * Pressing Leave is a choice, and grants nothing.
     */
    public const RETURN_PASS_MINUTES = self::IDLE_MINUTES;

    public function grantReturnPass(int $userId): void
    {
        Cache::put($this->returnPassKey($userId), true, now()->addMinutes(self::RETURN_PASS_MINUTES));
    }

    public function hasReturnPass(int $userId): bool
    {
        return Cache::has($this->returnPassKey($userId));
    }

    public function forgetReturnPass(int $userId): void
    {
        Cache::forget($this->returnPassKey($userId));
    }

    /** Keyed by code as well as id, so a pass can never carry over to another room. */
    private function returnPassKey(int $userId): string
    {
        return "room:{$this->id}:{$this->code}:return-pass:{$userId}";
    }

    /**
     * Record that a seated player is still here.
     *
     * Called on every member poll (every 5-15 seconds per player), so both writes
     * only happen once the stored value has gone stale. The staleness is read
     * first rather than left to the UPDATE's WHERE: on MySQL even an UPDATE
     * that changes nothing takes the row lock, and the room row is the lock
     * that bets and settlement queue on. The room goes straight to the table so
     * updated_at keeps meaning "the room changed".
     */
    public function markActive(int $userId): void
    {
        $now = now();

        if (! $this->last_active_at?->gt($now->copy()->subMinute())) {
            self::whereKey($this->id)->toBase()->update(['last_active_at' => $now]);
            $this->last_active_at = $now;
        }

        $seat = DB::table('game_players')
            ->where('game_room_id', $this->id)
            ->where('user_id', $userId);

        $lastSeen = (clone $seat)->value('last_seen_at');

        if ($lastSeen === null || Carbon::parse($lastSeen)->lt($now->copy()->subSeconds(30))) {
            $seat->update(['last_seen_at' => $now]);
        }
    }

    /**
     * Close the room for good, if nobody has been active in it since $cutoff.
     *
     * Idleness is re-read under the row lock, so a player who polls between the
     * sweep's query and this call keeps their room. Returns whether it closed.
     */
    public function closeIfIdleSince(\DateTimeInterface $cutoff): bool
    {
        return DB::transaction(function () use ($cutoff) {
            $room = self::whereKey($this->id)->lockForUpdate()->first();

            // A room that never recorded activity falls back to its last change.
            if ($room === null || ($room->last_active_at ?? $room->updated_at) >= $cutoff) {
                return false;
            }

            $room->refundOpenStakes();
            $room->delete();

            return true;
        });
    }

    /**
     * Unseat every player not seen since $cutoff, as long as someone is left.
     *
     * With nobody active the room is left exactly as it is: that is an empty
     * table, which rooms:close-idle closes after IDLE_MINUTES — and until then
     * anyone coming back finds their seat. Once a single player is active
     * again, the absent ones are removed. If the host is among them, the bank
     * passes to the earliest-joined player still here (see handHostTo).
     *
     * Absent players' bets stay where they are: settlement pays bets, not
     * seats, so a player who leaves mid-round is still settled with everyone.
     *
     * Rechecked under the row lock, so a player who polls mid-sweep stays.
     *
     * @return array{left: \Illuminate\Support\Collection<int, User>, host: ?User, round_voided: bool}|null
     *         null when nobody was removed
     */
    public function removeAbsentPlayersSince(\DateTimeInterface $cutoff): ?array
    {
        return DB::transaction(function () use ($cutoff) {
            $room = self::whereKey($this->id)->lockForUpdate()->first();

            if ($room === null) {
                return null;
            }

            // A seat that never recorded a poll counts from when it was taken.
            [$active, $absent] = $room->players()->get()->partition(
                fn (User $p) => Carbon::parse($p->pivot->last_seen_at ?? $p->pivot->joined_at)->gte($cutoff)
            );

            if ($absent->isEmpty() || $active->isEmpty()) {
                return null;
            }

            $room->players()->detach($absent->modelKeys());

            foreach ($absent as $player) {
                $room->grantReturnPass($player->id);
            }

            $host = null;
            $voided = false;

            if ($absent->contains('id', $room->host_user_id)) {
                $host   = $active->first();   // players() is ordered by joined_at
                $voided = $room->handHostTo($host);
            }

            return ['left' => $absent->values(), 'host' => $host, 'round_voided' => $voided];
        });
    }

    /**
     * Make $user the host (the banker). Must run under this room's row lock.
     *
     * The banker pays every winner and collects every loss, so a round already
     * in play belongs to the banker it was staked against. Passing it to someone
     * else mid-round would make them cover bets they never agreed to — and they
     * may have a bet of their own on the table. So a round in betting or
     * spinning is called off: every stake goes back and the room returns to
     * waiting, for the new host to open afresh.
     *
     * @return bool whether a round in play was called off
     */
    public function handHostTo(User $user): bool
    {
        $voided = in_array($this->status, ['betting', 'spinning'], true);

        if ($voided) {
            $this->refundOpenStakes();
            $this->bets()->delete();
        }

        $this->update([
            'host_user_id' => $user->id,
            'status'       => $voided ? 'waiting' : $this->status,
            'result'       => $voided ? null : $this->result,
        ]);

        return $voided;
    }

    /**
     * Stakes leave a player's balance when the bet is placed, and only
     * settlement puts them anywhere. A round that ends any other way (the room
     * closing, the banker changing) has to give them back, or the money simply
     * vanishes. A finished round's bets are already paid out and are left alone.
     *
     * Must run under this room's row lock.
     */
    private function refundOpenStakes(): void
    {
        if (! in_array($this->status, ['betting', 'spinning'], true)) {
            return;
        }

        foreach ($this->bets()->get() as $bet) {
            User::whereKey($bet->user_id)->increment('balance', $bet->amount);
        }
    }

    /**
     * Characters a generated code can contain. I, O, 0 and 1 are left out
     * because players read codes aloud and off screens.
     */
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 6;

    /**
     * The one definition of what a room code looks like: the alphabet above, in
     * either case, exactly CODE_LENGTH long. Used as the route constraint, the
     * broadcast channel constraint, and the lookup guard, so all three agree.
     *
     * Kept in step with CODE_ALPHABET by a test — change one and the other must
     * follow.
     */
    public const CODE_PATTERN = '[A-HJ-NP-Za-hj-np-z2-9]{6}';

    public static function isValidCode(string $code): bool
    {
        return (bool) preg_match('/^'.self::CODE_PATTERN.'$/', $code);
    }

    /** Room codes are stored uppercase — look them up case-insensitively. */
    public static function findByCode(string $code): ?self
    {
        $code = strtoupper(trim($code));

        // Anything that is not shaped like a code cannot match one, so it is
        // turned away here rather than spent on a query. Callers reach this with
        // raw input — a channel name, a stored breadcrumb — not just with route
        // parameters the router has already constrained.
        if (! self::isValidCode($code)) {
            return null;
        }

        return self::where('code', $code)->first();
    }

    public static function findByCodeOrFail(string $code): self
    {
        return self::findByCode($code)
            ?? throw (new ModelNotFoundException)->setModel(self::class);
    }

    public static function generateCode(): string
    {
        do {
            $code = strtoupper(substr(str_shuffle(self::CODE_ALPHABET), 0, self::CODE_LENGTH));
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
