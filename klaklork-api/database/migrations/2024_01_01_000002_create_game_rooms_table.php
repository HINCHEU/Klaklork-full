<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->foreignId('host_user_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['waiting', 'betting', 'spinning', 'finished'])->default('waiting');
            $table->unsignedBigInteger('bet_amount')->default(500);
            $table->unsignedInteger('max_players')->default(10);
            $table->json('result')->nullable(); // 3 image slot results
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_rooms');
    }
};
