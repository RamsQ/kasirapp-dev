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

    /**
     * Create a new event instance.
     */
    public function __construct($userId)
    {
        // Tetap simpan userId untuk kebutuhan data broadcast jika diperlukan
        $this->userId = (int) $userId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn()
    {
        /**
         * Gunakan channel global 'orders' agar Kasir A dan Kasir B 
         * bisa saling mendengarkan event satu sama lain.
         */
        return new Channel('orders');
    }

    /**
     * Nama event yang akan didengarkan oleh Laravel Echo
     */
    public function broadcastAs()
    {
        return 'order.placed';
    }

    /**
     * Data yang akan dikirimkan ke frontend
     */
    public function broadcastWith()
    {
        return [
            'userId'  => $this->userId,
            'message' => 'Ada pesanan masuk baru!',
            'time'    => now()->toDateTimeString(),
        ];
    }
}