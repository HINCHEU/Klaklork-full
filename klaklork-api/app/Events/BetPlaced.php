<?php

namespace App\Events;

use App\Models\Bet;
use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BetPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GameRoom $room,
        public User $user,
        public Bet $bet
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'BetPlaced';
    }

    public function broadcastWith(): array
    {
        return [
            'user'        => ['id' => $this->user->id, 'name' => $this->user->name],
            'animal_slot' => $this->bet->animal_slot,
            'amount'      => $this->bet->amount,
        ];
    }
}
