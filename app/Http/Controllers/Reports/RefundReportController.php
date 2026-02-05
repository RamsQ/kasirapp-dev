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
        // 1. Query Dasar: Ambil hanya transaksi yang statusnya 'refunded'
        $query = Transaction::query()
            ->with(['cashier', 'customer'])
            ->where('payment_status', 'refunded');

        // 2. Filter Berdasarkan Tanggal (Jika ada input dari user)
        if ($request->start_date) {
            $query->whereDate('updated_at', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('updated_at', '<=', $request->end_date);
        }

        // 3. Hitung Total Uang yang Dikembalikan
        $totalRefund = (clone $query)->sum('grand_total');

        // 4. Ambil Data dengan Pagination (10 per halaman)
        // Kita urutkan berdasarkan 'updated_at' karena itu waktu saat refund terjadi
        $refunds = $query->latest('updated_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Dashboard/Reports/Refund', [
            'refunds'     => $refunds,
            'totalRefund' => (int) $totalRefund,
            'filters'     => $request->only(['start_date', 'end_date']),
        ]);
    }
}