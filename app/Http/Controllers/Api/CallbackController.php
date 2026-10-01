<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CallbackController extends Controller
{
    /**
     * Ke sebagai alias callback lama. Semua status parsing, signature
     * verification, dan stock consumption didelegasikan ke controller canonical.
     */
    public function notification(Request $request)
    {
        return app(PaymentController::class)->handleNotification($request);
    }
}
