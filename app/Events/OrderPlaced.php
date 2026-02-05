<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;

    public function __construct($userId)
    {
        $this->userId = (int) $userId;
    }

    public function broadcastOn()
    {
        // SAMAKAN: Gunakan Channel (Public) bukan PrivateChannel
        return new Channel('public-order.' . $this->userId);
    }

    public function broadcastAs()
    {
        // SAMAKAN: Gunakan order.placed agar sesuai dengan listener di frontend
        return 'order.placed';
    }

    public function broadcastWith()
    {
        return [
            'userId' => $this->userId,
            'message' => 'Ada pesanan masuk baru!',
        ];
    }
}