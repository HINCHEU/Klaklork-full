<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a seated player last showed any sign of life in the room.
 *
 * A player who closes the tab never calls /leave, so an empty-looking table can
 * still have a full roster. This column is what rooms:close-idle reads to tell
 * an abandoned room from a quiet one. Existing rooms start from their
 * updated_at, so anything already long dead is swept on the first run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->index()->after('result');
        });

        DB::table('game_rooms')->update(['last_active_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropIndex(['last_active_at']);
            $table->dropColumn('last_active_at');
        });
    }
};
