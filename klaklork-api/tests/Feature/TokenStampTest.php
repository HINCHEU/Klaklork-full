<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenStampTest extends TestCase
{
    use RefreshDatabase;

    private function hit(string $token): ?string
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertOk();

        return PersonalAccessToken::first()->last_used_at?->toDateTimeString();
    }

    public function test_last_used_at_is_stamped_at_most_once_a_minute(): void
    {
        $this->freezeSecond();
        $token = User::factory()->create()->createToken('guest')->plainTextToken;

        $first = $this->hit($token);
        $this->assertSame(now()->toDateTimeString(), $first);

        $this->travel(30)->seconds();
        $this->assertSame($first, $this->hit($token), 'a stamp 30 s after the last is skipped');

        $this->travel(31)->seconds();
        $this->assertSame(now()->toDateTimeString(), $this->hit($token), 'a minute on, it is written again');
    }
}
