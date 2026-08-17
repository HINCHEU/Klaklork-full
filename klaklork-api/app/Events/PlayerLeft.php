<?php

namespace App\Events;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerLeft implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public GameRoom $room, public User $user) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'PlayerLeft';
    }

    public function broadcastWith(): array
    {
        return [
            'user'          => ['id' => $this->user->id, 'name' => $this->user->name],
            'players_count' => $this->room->players()->count(),
        ];
    }
}
