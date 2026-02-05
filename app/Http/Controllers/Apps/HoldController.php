<?php
namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use Illuminate\Http\Request;

class HoldController extends Controller
{
    // Simpan keranjang ke tabel holds
    public function store(Request $request)
    {
        Hold::create([
            'ref_number' => $request->ref_number,
            'cart_data'  => $request->cart_items,
            'total'      => $request->total,
            'user_id'    => auth()->id(),
        ]);

        return back()->with('success', 'Transaksi Berhasil Ditunda');
    }

    // Hapus data hold setelah berhasil di-restore/dibayar
    public function destroy($id)
    {
        $hold = Hold::findOrFail($id);
        $hold->delete();

        return back()->with('success', 'Data hold dihapus');
    }
}