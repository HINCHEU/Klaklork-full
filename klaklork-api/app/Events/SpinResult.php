<?php

namespace App\Events;

use App\Models\GameRoom;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SpinResult implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public GameRoom $room, public array $settlement = []) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'SpinResult';
    }

    public function broadcastWith(): array
    {
        return [
            'result'     => $this->room->result,
            'status'     => $this->room->status,
            // Who paid whom this round — the host settles with every player
            'settlement' => $this->settlement,
            'bets'   => $this->room->bets->map(fn($b) => [
                'user'        => ['id' => $b->user->id, 'name' => $b->user->name],
                'animal_slot' => $b->animal_slot,
                'amount'      => $b->amount,
                'won_amount'  => $b->won_amount,
            ]),
        ];
    }
}
