<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\StockIntelligenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        protected FinancialReportService $financialReports,
        protected StockIntelligenceService $stockIntelligence,
    ) {}

    public function index()
    {
        // --- DATA BASIC (HANYA YANG SUDAH LUNAS) ---
        $totalCategories = Category::count();
        $totalProducts = Product::count();

        $today = Carbon::today();
        $todayFinancials = $this->financialReports->summary(
            Transaction::whereDate('created_at', $today),
            Expense::whereDate('date', $today)
        );

        $totalTransactions = $todayFinancials['transaction_count'];
        $totalUsers = User::count();
        $totalRevenue = $todayFinancials['net_revenue'];
        $totalTaxCollected = $todayFinancials['tax_collected'];
        $totalProfit = $todayFinancials['net_profit'];
        $todayTransactions = $todayFinancials['transaction_count'];
        $averageOrder = $todayTransactions > 0
            ? $todayFinancials['net_revenue'] / $todayTransactions
            : 0;
        $lowStock = $this->stockIntelligence->lowStockSummary();

        // --- 1. PEMBAYARAN HARI INI (gross/tax-inclusive) ---
        // Pemecahan metode tetap memakai total tagihan yang benar-benar masuk
        // ke laci/settlement; omzet bersih dan pajak ditampilkan terpisah.
        $todayPaymentBreakdown = Transaction::where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->whereDate('created_at', $today)
            ->select('payment_method', DB::raw('COALESCE(SUM(grand_total), 0) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');
        $todayCashRevenue = (int) ($todayPaymentBreakdown->get('cash', 0));
        $todayDigitalRevenue = (int) $todayPaymentBreakdown
            ->only(['midtrans', 'xendit', 'qris_manual', 'transfer'])
            ->sum();
        $todayOtherRevenue = max(
            0,
            (int) $todayFinancials['billed_revenue'] - $todayCashRevenue - $todayDigitalRevenue
        );

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
            ->where('status', '!=', 'refunded')
            ->whereDate('created_at', '>=', $today->copy()->subDays(11))
            ->whereDate('created_at', '<=', $today)
            ->selectRaw('DATE(created_at) as date, SUM(COALESCE(taxable_amount, grand_total - COALESCE(tax_amount, 0))) as total')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->take(12)
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date,
                    'label' => Carbon::parse($row->date)->format('d M'),
                    'total' => (int) $row->total,
                ];
            })
            ->reverse()
            ->values();

        // --- 4. TOP 5 PRODUK TERLARIS (DARI TRANSAKSI LUNAS) ---
        $topProducts = TransactionDetail::whereHas('transaction', function ($q) use ($today) {
            $q->where('payment_status', 'paid')
                ->where('status', '!=', 'refunded')
                ->whereDate('created_at', $today);
        })
            ->select('product_id', DB::raw('SUM(qty) as qty'), DB::raw('SUM(price) as total'))
            ->with('product:id,title')
            ->groupBy('product_id')
            ->orderByDesc('qty')
            ->take(5)
            ->get()
            ->map(function ($detail) {
                return [
                    'name' => $detail->product?->title ?? 'Produk terhapus',
                    'qty' => (int) $detail->qty,
                    'total' => (int) $detail->total,
                ];
            });

        // --- 5. TRANSAKSI TERBARU (HANYA YANG LUNAS) ---
        $recentTransactions = Transaction::with('cashier:id,name', 'customer:id,name')
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->whereDate('created_at', $today)
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($transaction) {
                return [
                    'invoice' => $transaction->invoice,
                    'date' => Carbon::parse($transaction->created_at)->format('d M Y'),
                    'customer' => $transaction->customer?->name ?? '-',
                    'cashier' => $transaction->cashier?->name ?? '-',
                    'total' => (int) $transaction->grand_total,
                    'method' => $transaction->payment_method,
                    'status' => $transaction->payment_status,
                ];
            });

        // --- 6. TOP 5 PELANGGAN (DARI TRANSAKSI LUNAS) ---
        $topCustomers = Transaction::where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->whereDate('created_at', $today)
            ->select('customer_id', DB::raw('COUNT(*) as orders'), DB::raw('SUM(COALESCE(taxable_amount, grand_total - COALESCE(tax_amount, 0))) as total'))
            ->with('customer:id,name')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->orderByDesc('total')
            ->take(5)
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->customer?->name ?? 'Pelanggan',
                    'orders' => (int) $row->orders,
                    'total' => (int) $row->total,
                ];
            });

        return Inertia::render('Dashboard/Index', [
            'totalCategories' => $totalCategories,
            'totalProducts' => $totalProducts,
            'totalTransactions' => $totalTransactions,
            'totalUsers' => $totalUsers,
            'revenueTrend' => $revenueTrend,
            'totalRevenue' => (int) $totalRevenue,
            'totalTaxCollected' => (int) $totalTaxCollected,
            'totalProfit' => (int) $totalProfit,
            'totalHpp' => (int) $todayFinancials['hpp'],
            'totalAppExpenses' => (int) $todayFinancials['app_expenses'],
            'totalOperatingExpenses' => (int) $todayFinancials['operating_expenses'],
            'profitVariance' => (int) $todayFinancials['profit_variance'],
            'averageOrder' => (int) round($averageOrder),
            'todayTransactions' => (int) $todayTransactions,
            'todayBilledRevenue' => (int) $todayFinancials['billed_revenue'],
            'todayNetRevenue' => (int) $todayFinancials['net_revenue'],
            'todayOtherRevenue' => (int) $todayOtherRevenue,
            'lowStockProducts' => $lowStock['products'],
            'lowStockIngredients' => $lowStock['ingredients'],

            // Data pembayaran hari ini (gross/tax-inclusive)
            'todayCashRevenue' => (int) $todayCashRevenue,
            'todayDigitalRevenue' => (int) $todayDigitalRevenue,

            'topProducts' => $topProducts,
            'recentTransactions' => $recentTransactions,
            'topCustomers' => $topCustomers,

            // Data expired
            'expiredProducts' => $expiredProducts,
            'expiringProducts' => $expiringProducts,
        ]);
    }
}
