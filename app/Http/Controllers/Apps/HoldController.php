<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Events\OrderPlaced; 
use App\Events\OrderDeleted;
use Illuminate\Http\Request;

class HoldController extends Controller
{
    /**
     * Simpan keranjang ke tabel holds
     */
    public function store(Request $request)
    {
        $hold = Hold::create([
            'ref_number' => $request->ref_number,
            'cart_data'  => $request->cart_items,
            'total'      => $request->total,
            'user_id'    => auth()->id(),
        ]);

        /**
         * TRIGGER REALTIME
         * Menghapus ->toOthers() agar sinyal dikirim secara global.
         * Ini memastikan Kasir A (Admin) langsung menerima update saat Kasir B simpan order.
         */
        broadcast(new OrderPlaced(auth()->id()));

        return back()->with('success', 'Transaksi Berhasil Ditunda');
    }

    /**
     * Hapus data hold setelah berhasil di-restore/dibayar/dihapus manual
     */
    public function destroy($id)
    {
        $hold = Hold::findOrFail($id);
        $userId = $hold->user_id; 
        $hold->delete();

        /**
         * TRIGGER REALTIME
         * Menghapus ->toOthers() untuk menjamin sinkronisasi dua arah antar role.
         */
        broadcast(new OrderDeleted($userId));

        return back()->with('success', 'Data hold dihapus');
    }
}