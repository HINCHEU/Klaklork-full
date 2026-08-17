<?php

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Room feed — the same membership rule the REST endpoints enforce.
 *
 * These events carry balances and the round settlement, so the socket has to be
 * gated exactly like GET /api/games/{code}. Without this the channel would be
 * public and a room code alone would be enough to watch a table's money move.
 */
Broadcast::channel('game.{code}', function (User $user, string $code) {
    // Channel names arrive straight from the client and never pass through the
    // router, so this code is unconstrained input — findByCode() rejects
    // anything not shaped like a room code before it reaches a query.
    $room = GameRoom::findByCode($code);

    return $room !== null && $room->hasPlayer($user->id);
});
