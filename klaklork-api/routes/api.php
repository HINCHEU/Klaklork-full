<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BetController;
use App\Http\Controllers\Api\GameRoomController;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user',    [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Game rooms
    Route::get('/games',                        [GameRoomController::class, 'index']);
    Route::post('/games',                       [GameRoomController::class, 'store']);
    Route::get('/games/{code}',                 [GameRoomController::class, 'show']);
    Route::post('/games/{code}/join',           [GameRoomController::class, 'join']);
    Route::post('/games/{code}/open-betting',   [GameRoomController::class, 'openBetting']);
    Route::post('/games/{code}/spin',           [GameRoomController::class, 'spin']);
    Route::post('/games/{code}/stop',           [GameRoomController::class, 'stop']);

    // Bets
    Route::get('/games/{code}/bets',  [BetController::class, 'index']);
    Route::post('/games/{code}/bets', [BetController::class, 'store']);
});
