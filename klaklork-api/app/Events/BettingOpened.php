<?php

namespace App\Events;

use App\Models\GameRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BettingOpened implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public GameRoom $room) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->room->code}")];
    }

    public function broadcastWith(): array
    {
        return [
            'status'     => 'betting',
            'bet_amount' => $this->room->bet_amount,
        ];
    }
}
