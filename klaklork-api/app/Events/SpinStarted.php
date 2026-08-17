<?php

namespace App\Events;

use App\Models\GameRoom;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SpinStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public GameRoom $room) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'SpinStarted';
    }

    public function broadcastWith(): array
    {
        return ['status' => 'spinning'];
    }
}
