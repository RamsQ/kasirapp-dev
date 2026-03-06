<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RefundReportController extends Controller
{
    /**
     * Menampilkan halaman laporan refund.
     */
    public function index(Request $request)
    {
        // 1. Query Dasar: Gunakan kolom 'status', bukan 'payment_status'
        // Karena di TransactionController kita update-nya ke kolom 'status'
        $query = Transaction::query()
            ->with(['cashier:id,name', 'customer:id,name']) // Optimasi: hanya ambil id dan nama
            ->where('status', 'refunded');

        // 2. Filter Berdasarkan Tanggal
        if ($request->start_date) {
            $query->whereDate('updated_at', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('updated_at', '<=', $request->end_date);
        }

        // 3. Filter Berdasarkan No. Invoice (Tambahan agar lebih pro)
        if ($request->invoice) {
            $query->where('invoice', 'like', '%' . $request->invoice . '%');
        }

        // 4. Hitung Total Uang yang Dikembalikan
        // Menggunakan clone agar query utama tidak terganggu untuk pagination
        $totalRefund = (clone $query)->sum('grand_total');

        // 5. Ambil Data dengan Pagination
        // Diurutkan berdasarkan 'updated_at' (waktu admin klik tombol refund)
        $refunds = $query->latest('updated_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Dashboard/Reports/Refund', [
            'refunds'     => $refunds,
            'totalRefund' => (int) $totalRefund,
            'filters'     => $request->only(['start_date', 'end_date', 'invoice']),
        ]);
    }
}