<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
            ->withPivot('joined_at')
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
     * Record that a seated player is still here.
     *
     * Called on every member poll, so it only writes once a minute — the idle
     * cutoff is measured in tens of minutes and does not need more precision.
     * Goes straight to the table so updated_at keeps meaning "the room changed".
     */
    public function markActive(): void
    {
        if ($this->last_active_at?->gt(now()->subMinute())) {
            return;
        }

        $this->last_active_at = now();

        self::whereKey($this->id)->toBase()->update(['last_active_at' => $this->last_active_at]);
    }

    /**
     * Close the room for good, if nobody has been active in it since $cutoff.
     *
     * Idleness is re-read under the row lock, so a player who polls between the
     * sweep's query and this call keeps their room. Returns whether it closed.
     *
     * Stakes leave a player's balance when the bet is placed, and only
     * settlement puts them anywhere. A room abandoned mid-round (betting or
     * spinning) has never been settled, so those stakes go back to the players
     * before the bets are deleted with the room — otherwise the money simply
     * vanishes. A finished round's bets are already paid out and are left alone.
     */
    public function closeIfIdleSince(\DateTimeInterface $cutoff): bool
    {
        return DB::transaction(function () use ($cutoff) {
            $room = self::whereKey($this->id)->lockForUpdate()->first();

            // A room that never recorded activity falls back to its last change.
            if ($room === null || ($room->last_active_at ?? $room->updated_at) >= $cutoff) {
                return false;
            }

            if (in_array($room->status, ['betting', 'spinning'], true)) {
                foreach ($room->bets()->get() as $bet) {
                    User::whereKey($bet->user_id)->increment('balance', $bet->amount);
                }
            }

            $room->delete();

            return true;
        });
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
