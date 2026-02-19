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
     * Fungsi Tutup Shift (Menghitung Total Penjualan & Diskon)
     */
    public function close(Request $request)
    {
        $request->validate([
            'total_cash_physical' => 'required|numeric|min:0',
        ]);

        $shift = Shift::where('user_id', auth()->id())
            ->where('status', 'open')
            ->firstOrFail();

        // 1. Hitung Penjualan TUNAI & QRIS
        $totalCashSales = Transaction::where('shift_id', $shift->id)
                            ->where('payment_method', 'cash') 
                            ->where('payment_status', 'paid')
                            ->sum('grand_total');

        $totalQrisSales = Transaction::where('shift_id', $shift->id)
                            ->where('payment_method', 'qris') 
                            ->where('payment_status', 'paid')
                            ->sum('grand_total');

        // 2. Hitung TOTAL DISKON/PROMO selama shift ini
        $totalDiscounts = Transaction::where('shift_id', $shift->id)->sum('discount');

        // 3. Hitung Pengeluaran Kasir (Kas Keluar)
        $totalPettyCashOut = Expense::where('user_id', auth()->id())
                            ->whereBetween('created_at', [$shift->opened_at, now()])
                            ->sum('amount');

        // 4. Kalkulasi ekspektasi saldo tunai (Modal Awal + Jual Tunai - Kas Keluar)
        $expected = ($shift->starting_cash + $totalCashSales) - $totalPettyCashOut;
        $actual = $request->total_cash_physical;

        // 5. Update data shift
        $shift->update([
            'total_cash_expected' => $expected,
            'total_cash_actual'   => $actual,
            'total_qris_sales'    => $totalQrisSales,
            'total_discounts'     => $totalDiscounts, 
            'difference'          => $actual - $expected,
            'status'              => 'closed',
            'closed_at'           => now(),
        ]);

        return redirect()->route('shifts.print', $shift->id)->with('auto_print', true);
    }

    /**
     * Halaman Cetak Laporan Shift
     */
    public function print(Shift $shift)
    {
        // Ambil rincian transaksi untuk laporan
        $totalCashSales = Transaction::where('shift_id', $shift->id)
            ->where('payment_method', 'cash')
            ->where('payment_status', 'paid')
            ->sum('grand_total');

        $totalQrisSales = Transaction::where('shift_id', $shift->id)
            ->where('payment_method', 'qris')
            ->where('payment_status', 'paid')
            ->sum('grand_total');

        // Hitung total diskon khusus untuk cetakan
        $totalDiscounts = Transaction::where('shift_id', $shift->id)->sum('discount');

        $pettyCashOut = Expense::where('user_id', $shift->user_id)
            ->whereBetween('created_at', [$shift->opened_at, $shift->closed_at ?? now()])
            ->get();

        $shift->load('user');
        $shift->total_qris_sales = $totalQrisSales;
        $shift->total_cash_sales = $totalCashSales;
        $shift->total_discounts  = $totalDiscounts; 
        $shift->petty_cash_out   = $pettyCashOut->sum('amount');
        $shift->expense_details  = $pettyCashOut; 

        return Inertia::render('Dashboard/Shifts/Print', [
            'shift'          => $shift,
            'receiptSetting' => ReceiptSetting::first(),
            'auto_print'     => session('auto_print', false)
        ]);
    }

    /**
     * Riwayat Shift (Laporan Shift)
     * Penyesuaian agar Riwayat memiliki data lengkap untuk CETAK ULANG
     */
    public function index(Request $request)
    {
        $shifts = Shift::with(['user:id,name'])
            ->withSum(['transactions as total_cash_sales' => function($query) {
                $query->where('payment_method', 'cash')->where('payment_status', 'paid');
            }], 'grand_total')
            ->withSum(['transactions as total_qris_sales' => function($query) {
                $query->where('payment_method', 'qris')->where('payment_status', 'paid');
            }], 'grand_total')
            ->withSum('transactions as total_discounts', 'discount')
            ->when($request->date, fn($q, $date) => $q->whereDate('opened_at', $date))
            ->orderBy('id', 'desc')
            ->paginate(10)
            ->withQueryString();

        // Agar riwayat bisa dicetak ulang dengan detail pengeluaran, kita perlu melampirkan 
        // detail pengeluaran pada setiap shift di halaman riwayat (Opsional/Lazy Loading di Frontend)
        // Namun untuk performa cetak ulang langsung, kita kirimkan ReceiptSetting juga.
        
        return Inertia::render('Dashboard/Shifts/Index', [
            'shifts'         => $shifts,
            'receiptSetting' => ReceiptSetting::first(), // Penting untuk nama toko di cetakan ulang
            'filters'        => $request->all(['date'])
        ]);
    }
}