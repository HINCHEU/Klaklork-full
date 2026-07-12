<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Host User',
            'email' => 'host@example.com',
            'password' => bcrypt('password'),
            'balance' => 100000, // Rich host
        ]);

        User::factory()->create([
            'name' => 'Player User',
            'email' => 'player@example.com',
            'password' => bcrypt('password'),
            'balance' => 10000,
        ]);
    }
}
