<?php

namespace App\Events;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The bank moved to another player — because the host left, or went silent.
 *
 * When round_voided is set, the round that was in play was called off and every
 * stake refunded, so clients drop their bets and go back to waiting.
 */
class HostChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public GameRoom $room, public User $host, public bool $roundVoided) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'HostChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'host'         => ['id' => $this->host->id, 'name' => $this->host->name],
            'status'       => $this->room->status,
            'round_voided' => $this->roundVoided,
        ];
    }
}
