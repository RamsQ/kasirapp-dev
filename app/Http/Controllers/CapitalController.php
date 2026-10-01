<?php

namespace App\Http\Controllers;

use App\Models\Capital;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Modal usaha / setoran modal dari pemilik.
 *
 * Tabel `capitals` sudah ada dan dibaca laporan neraca, tapi sebelumnya tidak
 * punya route maupun halaman: setiap baris "Modal" di neraca selalu Rp 0.
 *
 * CATATAN AKUNTANSI: angka di sini adalah sisi EKUITAS, bukan aset. Uang yang
 * disetor pemilik sudah masuk ke "Kas di Laci" lewat shift opening atau ke
 * "Saldo Bank". Mencatat modal sebagai aset juga akan menghitung uang yang
 * sama dua kali, jadi laporan neraca tidak menjumlahkannya ke total aset.
 */
class CapitalController extends Controller
{
    public function index()
    {
        $capitals = Capital::latest('date')->latest('id')->paginate(20);

        return Inertia::render('Dashboard/Capitals/Index', [
            'auth' => [
                'user' => request()->user(),
                'permissions' => request()->user() ? request()->user()->getPermissionArray() : [],
            ],
            'capitals' => $capitals,
            'summary' => [
                'total_in' => (int) Capital::sum('amount'),
                'count' => Capital::count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        // Nominal modal tidak boleh negatif. Penarikan modal dicatat sebagai
        // pengeluaran dengan source "Modal Luar", bukan setoran negatif.
        $validated = $request->validate([
            'source' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
        ]);

        Capital::create([
            'source' => $validated['source'],
            'amount' => (float) $validated['amount'],
            'date' => $validated['date'],
        ]);

        return redirect()->route('capitals.index')->with('success', 'Setoran modal berhasil dicatat.');
    }

    public function destroy(Capital $capital)
    {
        $capital->delete();

        return redirect()->route('capitals.index')->with('success', 'Setoran modal berhasil dihapus.');
    }
}
