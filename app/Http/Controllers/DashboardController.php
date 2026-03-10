<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Profit;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        // --- DATA BASIC (HANYA YANG SUDAH LUNAS) ---
        $totalCategories   = Category::count();
        $totalProducts     = Product::count();
        
        // Filter 'paid' agar transaksi pending/gagal tidak merusak statistik
        $totalTransactions = Transaction::where('payment_status', 'paid')->count();
        $totalUsers        = User::count();
        $totalRevenue      = Transaction::where('payment_status', 'paid')->sum('grand_total');
        
        // FIX BUG: Hitung laba hanya dari transaksi yang sudah LUNAS (Paid)
        // Sebelumnya Profit::sum('total') mengambil semua, termasuk yang masih pending/cancel
        $totalProfit       = Profit::whereHas('transaction', function($q) {
                                $q->where('payment_status', 'paid');
                            })->sum('total');

        $averageOrder      = Transaction::where('payment_status', 'paid')->avg('grand_total') ?? 0;
        $todayTransactions = Transaction::where('payment_status', 'paid')
            ->whereDate('created_at', Carbon::today())
            ->count();

        // --- 1. LOGIKA PENDAPATAN TERPISAH (AUDIT FINANCIAL) ---
        // Menghitung uang yang benar-benar masuk (PAID) berdasarkan metode
        $totalCashRevenue = Transaction::where('payment_status', 'paid')
            ->where('payment_method', 'cash')
            ->sum('grand_total');

        $totalDigitalRevenue = Transaction::where('payment_status', 'paid')
            ->whereIn('payment_method', ['midtrans', 'xendit', 'qris_manual', 'transfer'])
            ->sum('grand_total');

        // Ringkasan khusus hari ini (Hanya yang PAID)
        $todayCashRevenue = Transaction::where('payment_status', 'paid')
            ->whereDate('created_at', Carbon::today())
            ->where('payment_method', 'cash')
            ->sum('grand_total');

        $todayDigitalRevenue = Transaction::where('payment_status', 'paid')
            ->whereDate('created_at', Carbon::today())
            ->whereIn('payment_method', ['midtrans', 'xendit', 'qris_manual', 'transfer'])
            ->sum('grand_total');

        // --- 2. LOGIKA EXPIRED DATE (PRODUK) ---
        $expiredProducts = Product::whereNotNull('expired_date')
            ->whereDate('expired_date', '<', Carbon::now())
            ->limit(10)
            ->get();

        $expiringProducts = Product::whereNotNull('expired_date')
            ->whereDate('expired_date', '>=', Carbon::now())
            ->whereDate('expired_date', '<=', Carbon::now()->addDays(30))
            ->orderBy('expired_date', 'asc')
            ->limit(10)
            ->get();

        // --- 3. LOGIKA TREN PENDAPATAN (12 HARI TERAKHIR - HANYA PAID) ---
        $revenueTrend = Transaction::where('payment_status', 'paid')
            ->selectRaw('DATE(created_at) as date, SUM(grand_total) as total')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->take(12)
            ->get()
            ->map(function ($row) {
                return [
                    'date'  => $row->date,
                    'label' => Carbon::parse($row->date)->format('d M'),
                    'total' => (int) $row->total,
                ];
            })
            ->reverse()
            ->values();

        // --- 4. TOP 5 PRODUK TERLARIS (DARI TRANSAKSI LUNAS) ---
        $topProducts = TransactionDetail::whereHas('transaction', function($q) {
                $q->where('payment_status', 'paid');
            })
            ->select('product_id', DB::raw('SUM(qty) as qty'), DB::raw('SUM(price) as total'))
            ->with('product:id,title')
            ->groupBy('product_id')
            ->orderByDesc('qty')
            ->take(5)
            ->get()
            ->map(function ($detail) {
                return [
                    'name'  => $detail->product?->title ?? 'Produk terhapus',
                    'qty'   => (int) $detail->qty,
                    'total' => (int) $detail->total,
                ];
            });

        // --- 5. TRANSAKSI TERBARU (HANYA YANG LUNAS) ---
        $recentTransactions = Transaction::with('cashier:id,name', 'customer:id,name')
            ->where('payment_status', 'paid')
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($transaction) {
                return [
                    'invoice'  => $transaction->invoice,
                    'date'     => Carbon::parse($transaction->created_at)->format('d M Y'),
                    'customer' => $transaction->customer?->name ?? '-',
                    'cashier'  => $transaction->cashier?->name ?? '-',
                    'total'    => (int) $transaction->grand_total,
                    'method'   => $transaction->payment_method,
                    'status'   => $transaction->payment_status,
                ];
            });

        // --- 6. TOP 5 PELANGGAN (DARI TRANSAKSI LUNAS) ---
        $topCustomers = Transaction::where('payment_status', 'paid')
            ->select('customer_id', DB::raw('COUNT(*) as orders'), DB::raw('SUM(grand_total) as total'))
            ->with('customer:id,name')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->orderByDesc('total')
            ->take(5)
            ->get()
            ->map(function ($row) {
                return [
                    'name'   => $row->customer?->name ?? 'Pelanggan',
                    'orders' => (int) $row->orders,
                    'total'  => (int) $row->total,
                ];
            });

        return Inertia::render('Dashboard/Index', [
            'totalCategories'    => $totalCategories,
            'totalProducts'      => $totalProducts,
            'totalTransactions'  => $totalTransactions,
            'totalUsers'         => $totalUsers,
            'revenueTrend'       => $revenueTrend,
            'totalRevenue'       => (int) $totalRevenue,
            'totalProfit'        => (int) $totalProfit,
            'averageOrder'       => (int) round($averageOrder),
            'todayTransactions'  => (int) $todayTransactions,
            
            // Data Pendapatan Terpisah
            'totalCashRevenue'    => (int) $totalCashRevenue,
            'totalDigitalRevenue' => (int) $totalDigitalRevenue,
            'todayCashRevenue'    => (int) $todayCashRevenue,
            'todayDigitalRevenue' => (int) $todayDigitalRevenue,

            'topProducts'        => $topProducts,
            'recentTransactions' => $recentTransactions,
            'topCustomers'       => $topCustomers,
            
            // Data expired
            'expiredProducts'    => $expiredProducts,
            'expiringProducts'   => $expiringProducts,
        ]);
    }
}