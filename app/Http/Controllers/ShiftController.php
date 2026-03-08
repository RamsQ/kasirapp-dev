<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\Transaction;
use App\Models\ReceiptSetting;
use App\Models\Expense; 
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

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
     * Fungsi Tutup Shift (Menghitung Total Penjualan Digital & Tunai)
     */
    public function close(Request $request)
    {
        $request->validate([
            'total_cash_physical' => 'required|numeric|min:0',
        ]);

        $shift = Shift::where('user_id', auth()->id())
            ->where('status', 'open')
            ->firstOrFail();

        // 1. Hitung Penjualan TUNAI (Hanya yang lunas dan TIDAK di-refund)
        $totalCashSales = Transaction::where('shift_id', $shift->id)
                            ->where('payment_method', 'cash') 
                            ->where('payment_status', 'paid')
                            ->where('status', '!=', 'refunded')
                            ->sum('grand_total');

        // 2. Hitung Penjualan QRIS AUTO (Midtrans/Xendit)
        $totalMidtransSales = Transaction::where('shift_id', $shift->id)
                            ->whereIn('payment_method', ['midtrans', 'xendit']) 
                            ->where('payment_status', 'paid')
                            ->where('status', '!=', 'refunded')
                            ->sum('grand_total');

        // 3. Hitung Penjualan QRIS MANUAL
        $totalQrisManualSales = Transaction::where('shift_id', $shift->id)
                            ->where('payment_method', 'qris_manual') 
                            ->where('payment_status', 'paid')
                            ->where('status', '!=', 'refunded')
                            ->sum('grand_total');

        // 4. Hitung Penjualan TRANSFER
        $totalTransferSales = Transaction::where('shift_id', $shift->id)
                            ->where('payment_method', 'transfer') 
                            ->where('payment_status', 'paid')
                            ->where('status', '!=', 'refunded')
                            ->sum('grand_total');

        // 5. Hitung TOTAL DISKON
        $totalDiscounts = Transaction::where('shift_id', $shift->id)
                            ->where('status', '!=', 'refunded')
                            ->sum('discount');

        // 6. Hitung Pengeluaran Kasir (Kas Keluar)
        $totalPettyCashOut = Expense::where('user_id', auth()->id())
                            ->whereBetween('created_at', [$shift->opened_at, Carbon::now()])
                            ->sum('amount');

        // 7. Kalkulasi ekspektasi saldo tunai (Modal + Tunai - Keluar)
        $expected = ($shift->starting_cash + $totalCashSales) - $totalPettyCashOut;
        $actual = $request->total_cash_physical;

        // 8. Update data shift (SIMPAN SEMUA KE DATABASE)
        $shift->update([
            'total_cash_expected' => $expected,
            'total_cash_actual'   => $actual,
            'total_cash_sales'    => $totalCashSales,
            'total_midtrans_sales'=> $totalMidtransSales,
            'total_qris_sales'    => $totalQrisManualSales,
            'total_transfer_sales'=> $totalTransferSales,
            'total_discounts'     => $totalDiscounts, 
            'total_expense'       => $totalPettyCashOut, // Data resmi tersimpan di kolom baru
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
        $shift->load('user');
        
        // Recalculate digital sales untuk memastikan data fresh di tampilan
        $shift->total_midtrans_sales = Transaction::where('shift_id', $shift->id)->whereIn('payment_method', ['midtrans', 'xendit'])->where('status', '!=', 'refunded')->sum('grand_total');
        $shift->total_qris_sales     = Transaction::where('shift_id', $shift->id)->where('payment_method', 'qris_manual')->where('status', '!=', 'refunded')->sum('grand_total');
        $shift->total_transfer_sales = Transaction::where('shift_id', $shift->id)->where('payment_method', 'transfer')->where('status', '!=', 'refunded')->sum('grand_total');
        $shift->total_cash_sales     = Transaction::where('shift_id', $shift->id)->where('payment_method', 'cash')->where('status', '!=', 'refunded')->sum('grand_total');

        // Ambil rincian pengeluaran untuk detail struk
        $pettyCashDetails = Expense::where('user_id', $shift->user_id)
            ->whereBetween('created_at', [$shift->opened_at, $shift->closed_at ?? now()])
            ->get();

        // Mengambil dari kolom total_expense atau hitung manual jika data lama null
        $shift->petty_cash_out   = $shift->total_expense ?? $pettyCashDetails->sum('amount');
        $shift->expense_details  = $pettyCashDetails; 

        return Inertia::render('Dashboard/Shifts/Print', [
            'shift'          => $shift,
            'receiptSetting' => ReceiptSetting::first(),
            'auto_print'     => session('auto_print', false)
        ]);
    }

    /**
     * Riwayat Shift (Laporan Shift)
     */
    public function index(Request $request)
    {
        $shifts = Shift::with(['user:id,name'])
            ->withSum(['transactions as total_cash_sales' => function($query) {
                $query->where('payment_method', 'cash')->where('status', '!=', 'refunded');
            }], 'grand_total')
            ->withSum(['transactions as total_midtrans_sales' => function($query) {
                $query->whereIn('payment_method', ['midtrans', 'xendit'])->where('status', '!=', 'refunded');
            }], 'grand_total')
            ->withSum(['transactions as total_qris_sales' => function($query) {
                $query->where('payment_method', 'qris_manual')->where('status', '!=', 'refunded');
            }], 'grand_total')
            ->withSum(['transactions as total_transfer_sales' => function($query) {
                $query->where('payment_method', 'transfer')->where('status', '!=', 'refunded');
            }], 'grand_total')
            // FIX: Dihapus withSum expenses karena method relasi tidak ada di Model.
            // Kolom 'total_expense' sudah ada di tabel shifts, jadi otomatis terambil.
            ->when($request->date, fn($q, $date) => $q->whereDate('opened_at', $date))
            ->orderBy('id', 'desc')
            ->paginate(10)
            ->withQueryString();
        
        return Inertia::render('Dashboard/Shifts/Index', [
            'shifts'         => $shifts,
            'receiptSetting' => ReceiptSetting::first(),
            'filters'        => $request->all(['date'])
        ]);
    }
}