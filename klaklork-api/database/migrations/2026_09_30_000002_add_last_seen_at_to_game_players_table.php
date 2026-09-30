<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When each seated player last polled the room.
 *
 * The room-level last_active_at says whether anyone is at the table; this says
 * who. rooms:reassign-absent-hosts reads it to notice a host who closed the tab
 * while other players are still waiting on them to run the round. Existing
 * seats start from joined_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_players', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('joined_at');
        });

        DB::table('game_players')->update(['last_seen_at' => DB::raw('joined_at')]);
    }

    public function down(): void
    {
        Schema::table('game_players', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
