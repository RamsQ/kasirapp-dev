<?php

use Illuminate\Support\Facades\Broadcast;

// Logika izin: User boleh dengerin channel 'orders.{id}' jika:
// 1. Dia adalah pemilik ID tersebut
// 2. ATAU dia punya permission 'public.orders.index'
Broadcast::channel('orders.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id || $user->can('public.orders.index');
});