<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, Prunable;

    /**
     * A guest nobody has played as for this long is deleted by the scheduled
     * model:prune. A guest can only ever be resumed from the one browser holding
     * its token, so an abandoned one is dead weight; a player who does come back
     * later is sent to the name screen and starts over with a fresh balance.
     */
    public const GUEST_RETENTION_DAYS = 30;

    protected $fillable = [
        'name',
        'email',
        'password',
        'balance',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance' => 'integer',
        ];
    }

    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    public function gameRooms(): BelongsToMany
    {
        return $this->belongsToMany(GameRoom::class, 'game_players')
            ->withPivot('joined_at');
    }

    /**
     * Guests inactive for GUEST_RETENTION_DAYS.
     *
     * - Guests only: no password. Accounts from before guest entry keep their
     *   credentials and are never touched.
     * - Inactive: no token used or issued since the cutoff (Sanctum stamps
     *   last_used_at on every request), and the row itself unchanged since.
     * - Nothing left on a table: deleting a user cascades to their seats, their
     *   bets and any room they host, so anyone still seated, hosting, or holding
     *   a bet is skipped. The room sweeps clear those long before 30 days.
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(self::GUEST_RETENTION_DAYS);

        return static::query()
            ->whereNull('password')
            ->where('updated_at', '<', $cutoff)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('personal_access_tokens')
                ->whereColumn('personal_access_tokens.tokenable_id', 'users.id')
                ->where('personal_access_tokens.tokenable_type', static::class)
                ->where(fn ($q) => $q
                    ->where('personal_access_tokens.last_used_at', '>=', $cutoff)
                    ->orWhere(fn ($q) => $q
                        ->whereNull('personal_access_tokens.last_used_at')
                        ->where('personal_access_tokens.created_at', '>=', $cutoff))))
            ->whereDoesntHave('gameRooms')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('game_rooms')
                ->whereColumn('game_rooms.host_user_id', 'users.id'))
            ->whereDoesntHave('bets');
    }

    /** Tokens are polymorphic, with no foreign key to cascade — delete them first. */
    protected function pruning(): void
    {
        PersonalAccessToken::where('tokenable_type', static::class)
            ->where('tokenable_id', $this->id)
            ->delete();
    }
}
