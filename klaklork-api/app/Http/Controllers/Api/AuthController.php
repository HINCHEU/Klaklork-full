<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlayerNameRequest;
use App\Models\User;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /** Starting balance handed to every new player. */
    private const STARTING_BALANCE = 10000;

    /**
     * POST /api/guest — enter the game with just a display name.
     *
     * Returns a Sanctum token the browser keeps in localStorage, so closing
     * the tab and coming back resumes the same player (name + balance).
     */
    public function enter(PlayerNameRequest $request)
    {
        $user = User::create([
            'name'    => $request->displayName(),
            'balance' => self::STARTING_BALANCE,
        ]);

        return response()->json([
            'user'  => $user,
            'token' => $user->createToken('guest')->plainTextToken,
        ], 201);
    }

    /** GET /api/user — resume an existing session. */
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    /** PATCH /api/user — change display name without losing the session. */
    public function updateName(PlayerNameRequest $request)
    {
        $user = $request->user();
        $user->update(['name' => $request->displayName()]);

        return response()->json($user);
    }

    /** POST /api/logout — drop the session token for this browser. */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Session ended.']);
    }
}
