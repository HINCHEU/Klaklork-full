<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A room may be locked with a password.
 *
 * Null means the room is open, which is what every existing room becomes — the
 * lobby keeps working exactly as before for anyone who does not set one. The
 * column holds a bcrypt hash, never the password itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->string('password')->nullable()->after('max_players');
        });
    }

    public function down(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
