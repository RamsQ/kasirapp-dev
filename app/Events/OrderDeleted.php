<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;

    /**
     * Create a new event instance.
     */
    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn()
    {
        /**
         * Menggunakan channel yang sama dengan OrderPlaced ('orders')
         * agar semua kasir mendapatkan update penghapusan antrean secara bersamaan.
         */
        return new Channel('orders');
    }

    /**
     * Nama event yang didengarkan oleh Laravel Echo di frontend
     */
    public function broadcastAs()
    {
        return 'order.deleted';
    }

    /**
     * Data tambahan yang dikirim saat event dipicu
     */
    public function broadcastWith()
    {
        return [
            'userId' => $this->userId,
            'status' => 'deleted',
            'time'   => now()->toDateTimeString(),
        ];
    }
}