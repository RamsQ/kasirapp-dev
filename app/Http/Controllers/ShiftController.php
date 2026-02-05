<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\Transaction;
use App\Models\ReceiptSetting;
use App\Models\Expense; 
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShiftController extends Controller
{
    /**
     * Halaman untuk input modal awal
     */
    public function create()
    {
        return Inertia::render('Dashboard/Shifts/Create');
    }

    /**
     * Fungsi Buka Shift (Simpan modal awal)
     */
    public function store(Request $request)
    {
        $request->validate([
            'starting_cash' => 'required|numeric|min:0',
        ]);

        $existingShift = Shift::where('user_id', auth()->id())
            ->where('status', 'open')
            ->first();

        if ($existingShift) {
            return redirect()->route('transactions.index')->with('error', 'Anda masih memiliki shift yang aktif!');
        }

        Shift::create([
            'user_id'       => auth()->id(),
            'starting_cash' => $request->starting_cash,
            'opened_at'     => now(),
            'status'        => 'open'
        ]);

        return redirect()->route('transactions.index')->with('success', 'Shift berhasil dibuka!');
    }

    /**
     * [FIXED] Fungsi Tutup Shift (Nama diganti dari 'update' ke 'close' agar sync dengan Route)
     */
    public function close(Request $request)
    {
        $request->validate([
            'total_cash_physical' => 'required|numeric|min:0',
        ]);

        // Ambil shift yang sedang aktif
        $shift = Shift::where('user_id', auth()->id())
            ->where('status', 'open')
            ->firstOrFail();

        // 1. Hitung Penjualan TUNAI selama shift ini
        $totalCashSales = Transaction::where('shift_id', $shift->id)
                            ->where('payment_method', 'cash') 
                            ->where('payment_status', 'paid')
                            ->sum('grand_total');

        // 2. Hitung Pengeluaran Kasir (Kas Keluar) selama shift ini
        $totalPettyCashOut = Expense::where('user_id', auth()->id())
                            ->whereBetween('created_at', [$shift->opened_at, now()])
                            ->sum('amount');

        // 3. Kalkulasi ekspektasi saldo tunai (Modal Awal + Jual Tunai - Kas Keluar)
        $expected = ($shift->starting_cash + $totalCashSales) - $totalPettyCashOut;
        $actual = $request->total_cash_physical;

        // 4. Update data shift ke database
        $shift->update([
            'total_cash_expected' => $expected,
            'total_cash_actual'   => $actual,
            'difference'          => $actual - $expected,
            'status'              => 'closed',
            'closed_at'           => now(),
        ]);

        // 5. Alirkan ke halaman print dengan sinyal auto_print
        return redirect()->route('shifts.print', $shift->id)->with('auto_print', true);
    }

    /**
     * Halaman Khusus Review & Cetak Laporan Shift
     */
    public function print(Shift $shift)
    {
        // Hitung ulang data untuk tampilan laporan agar rinciannya lengkap
        $totalCashSales = Transaction::where('shift_id', $shift->id)
            ->where('payment_method', 'cash')
            ->where('payment_status', 'paid')
            ->sum('grand_total');

        $totalQrisSales = Transaction::where('shift_id', $shift->id)
            ->where('payment_method', 'qris')
            ->where('payment_status', 'paid')
            ->sum('grand_total');

        $pettyCashOut = Expense::where('user_id', $shift->user_id)
            ->whereBetween('created_at', [$shift->opened_at, $shift->closed_at ?? now()])
            ->get();

        $shift->load('user');
        $shift->total_qris_sales = $totalQrisSales;
        $shift->total_cash_sales = $totalCashSales;
        $shift->petty_cash_out = $pettyCashOut->sum('amount');
        $shift->expense_details = $pettyCashOut; 

        return Inertia::render('Dashboard/Shifts/Print', [
            'shift'          => $shift,
            'receiptSetting' => ReceiptSetting::first(),
            'auto_print'     => session('auto_print', false) // Mengirim status auto print ke React
        ]);
    }

    /**
     * Riwayat Shift (Laporan Shift)
     */
    public function index(Request $request)
    {
        $shifts = Shift::with('user:id,name')
            ->withSum(['transactions as total_cash_sales' => function($query) {
                $query->where('payment_method', 'cash')->where('payment_status', 'paid');
            }], 'grand_total')
            ->withSum(['transactions as total_qris_sales' => function($query) {
                $query->where('payment_method', 'qris')->where('payment_status', 'paid');
            }], 'grand_total')
            ->when($request->date, fn($q, $date) => $q->whereDate('opened_at', $date))
            ->orderBy('id', 'desc')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Dashboard/Shifts/Index', [
            'shifts'  => $shifts,
            'filters' => $request->all(['date'])
        ]);
    }
}