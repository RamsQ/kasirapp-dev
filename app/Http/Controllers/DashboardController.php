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
        // --- DATA BASIC ---
        $totalCategories   = Category::count();
        $totalProducts     = Product::count();
        $totalTransactions = Transaction::count();
        $totalUsers        = User::count();
        $totalRevenue      = Transaction::sum('grand_total');
        $totalProfit       = Profit::sum('total');
        $averageOrder      = Transaction::avg('grand_total') ?? 0;
        $todayTransactions = Transaction::whereDate('created_at', Carbon::today())->count();

        // --- 1. LOGIKA PENDAPATAN TERPISAH (BARU UNTUK KEUANGAN) ---
        // Memisahkan uang fisik laci dan uang digital untuk audit owner
        $totalCashRevenue = Transaction::where('payment_method', 'cash')->sum('grand_total');
        $totalDigitalRevenue = Transaction::whereIn('payment_method', ['midtrans', 'xendit', 'qris_manual', 'transfer'])
            ->sum('grand_total');

        // Ringkasan khusus hari ini
        $todayCashRevenue = Transaction::whereDate('created_at', Carbon::today())
            ->where('payment_method', 'cash')
            ->sum('grand_total');
        $todayDigitalRevenue = Transaction::whereDate('created_at', Carbon::today())
            ->whereIn('payment_method', ['midtrans', 'xendit', 'qris_manual', 'transfer'])
            ->sum('grand_total');

        // --- 2. LOGIKA EXPIRED DATE (FIXED) ---
        // A. Produk SUDAH Kadaluarsa (Tanggal < Hari Ini)
        $expiredProducts = Product::whereNotNull('expired_date')
            ->whereDate('expired_date', '<', Carbon::now())
            ->limit(10)
            ->get();

        // B. Produk AKAN Kadaluarsa (H-30)
        $expiringProducts = Product::whereNotNull('expired_date')
            ->whereDate('expired_date', '>=', Carbon::now())
            ->whereDate('expired_date', '<=', Carbon::now()->addDays(30))
            ->orderBy('expired_date', 'asc')
            ->limit(10)
            ->get();

        // --- 3. LOGIKA TREN PENDAPATAN (12 HARI TERAKHIR) ---
        $revenueTrend = Transaction::selectRaw('DATE(created_at) as date, SUM(grand_total) as total')
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

        // --- 4. TOP 5 PRODUK TERLARIS ---
        $topProducts = TransactionDetail::select('product_id', DB::raw('SUM(qty) as qty'), DB::raw('SUM(price) as total'))
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

        // --- 5. TRANSAKSI TERBARU ---
        $recentTransactions = Transaction::with('cashier:id,name', 'customer:id,name')
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
                    'method'   => $transaction->payment_method, // Tambahan info metode
                ];
            });

        // --- 6. TOP 5 PELANGGAN ---
        $topCustomers = Transaction::select('customer_id', DB::raw('COUNT(*) as orders'), DB::raw('SUM(grand_total) as total'))
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
            
            // Data Pendapatan Terpisah (Baru)
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