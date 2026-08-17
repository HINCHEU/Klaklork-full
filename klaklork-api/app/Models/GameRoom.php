<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
    ];

    protected $casts = [
        'result' => 'array',
        'bet_amount' => 'integer',
        'max_players' => 'integer',
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
