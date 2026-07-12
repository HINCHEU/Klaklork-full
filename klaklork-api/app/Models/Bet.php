<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bet extends Model
{
    protected $fillable = [
        'game_room_id',
        'user_id',
        'animal_slot',
        'amount',
        'won_amount',
    ];

    protected $casts = [
        'animal_slot' => 'integer',
        'amount' => 'integer',
        'won_amount' => 'integer',
    ];

    public function gameRoom(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
