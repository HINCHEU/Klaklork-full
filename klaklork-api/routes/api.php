<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BetController;
use App\Http\Controllers\Api\GameRoomController;
use App\Models\GameRoom;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Every {code} in this file has to be shaped like a room code. Anything else is
// not a room that could exist, so it is a 404 from the router — it never reaches
// a controller, a query, or a rate-limit bucket.
Route::pattern('code', GameRoom::CODE_PATTERN);

// Guest entry — name only, no password
Route::post('/guest', [AuthController::class, 'enter'])
    ->middleware('throttle:guest-entry');

// Private-channel authorization. The SPA authenticates with a bearer token, not
// a session cookie, so the handshake has to run through the Sanctum guard —
// every subscription to game.{code} is checked against routes/channels.php.
Broadcast::routes(['middleware' => ['auth:sanctum', 'throttle:broadcasting']]);

// Protected routes. The group carries a catch-all ceiling and the per-route
// limiters sit inside it, so a route's own budget and the overall one both have
// to hold — and a route added later is never unbounded by default.
// See AppServiceProvider::configureRateLimits().
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/user',     [AuthController::class, 'user']);
    Route::patch('/user',   [AuthController::class, 'updateName'])->middleware('throttle:write');
    Route::post('/logout',  [AuthController::class, 'logout']);

    // Game rooms
    Route::get('/games',                        [GameRoomController::class, 'index'])->middleware('throttle:read');
    Route::post('/games',                       [GameRoomController::class, 'store'])->middleware('throttle:create-room');
    Route::get('/games/{code}',                 [GameRoomController::class, 'show'])->middleware('throttle:read');
    Route::post('/games/{code}/join',           [GameRoomController::class, 'join'])->middleware('throttle:join-room');
    Route::post('/games/{code}/leave',          [GameRoomController::class, 'leave'])->middleware('throttle:write');
    Route::post('/games/{code}/open-betting',   [GameRoomController::class, 'openBetting'])->middleware('throttle:write');
    Route::post('/games/{code}/spin',           [GameRoomController::class, 'spin'])->middleware('throttle:write');
    Route::post('/games/{code}/stop',           [GameRoomController::class, 'stop'])->middleware('throttle:write');

    // Bets
    Route::get('/games/{code}/bets',  [BetController::class, 'index'])->middleware('throttle:read');
    Route::post('/games/{code}/bets', [BetController::class, 'store'])->middleware('throttle:write');
});
