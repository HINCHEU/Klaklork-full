<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        'result',
    ];

    protected $casts = [
        'result' => 'array',
        'bet_amount' => 'integer',
        'max_players' => 'integer',
    ];

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

    /** Room codes are stored uppercase — look them up case-insensitively. */
    public static function findByCodeOrFail(string $code): self
    {
        return self::where('code', strtoupper(trim($code)))->firstOrFail();
    }

    public static function generateCode(): string
    {
        do {
            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
