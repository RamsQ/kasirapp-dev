<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProfitReportController extends Controller
{
    public function __construct(protected FinancialReportService $financialReports) {}

    /**
     * Display the profit report.
     */
    public function index(Request $request)
    {
        $filters = [
            'start_date' => $request->input('start_date') ?: now()->subDays(6)->toDateString(),
            'end_date' => $request->input('end_date') ?: now()->toDateString(),
            'invoice' => $request->input('invoice'),
            'cashier_id' => $request->input('cashier_id'),
            'customer_id' => $request->input('customer_id'),
        ];

        // Query dasar transaksi (Sudah difilter agar hanya mengambil yang LUNAS dan bukan REFUND)
        $baseQuery = $this->applyFilters(
            Transaction::query(),
            $filters
        );

        // 1. Query untuk Daftar Tabel (PAGINATION)
        $transactions = (clone $baseQuery)
            ->with(['cashier:id,name', 'customer:id,name'])
            ->withSum('profits as total_profit', 'total')
            ->withSum('details as total_items', 'qty')
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        $canAllocateOperatingExpenses = ! $filters['invoice'] && ! $filters['customer_id'];
        $expenseQuery = $canAllocateOperatingExpenses
            ? Expense::query()
                ->where('category', '!=', 'Pelunasan Hutang')
                ->when($filters['start_date'], fn ($q, $date) => $q->whereDate('date', '>=', $date))
                ->when($filters['end_date'], fn ($q, $date) => $q->whereDate('date', '<=', $date))
                ->when($filters['cashier_id'], fn ($q, $cashier) => $q->where('user_id', $cashier))
            : null;

        $financials = $this->financialReports->summary($baseQuery, $expenseQuery);
        $revenueTotal = $financials['net_revenue'];
        $taxCollected = $financials['tax_collected'];
        $billedTotal = $financials['billed_revenue'];
        $appExpenseAccount = $financials['app_expenses'];
        $totalHpp = $financials['hpp'];
        $grossProfitBeforePlatform = $financials['gross_profit_before_platform'];
        $grossMargin = $financials['contribution_profit'];
        $totalOperatingExpenses = $financials['operating_expenses'];
        $netProfitFinal = $financials['net_profit'];
        $netRevenue = $financials['net_revenue'] - $appExpenseAccount;
        $ordersCount = $financials['transaction_count'];
        $itemsSold = $financials['items_sold'];

        // Ambil transaksi terbaik berdasarkan profit terbesar (hanya transaksi valid/lunas)
        $bestTransaction = (clone $baseQuery)
            ->withSum('profits as total_profit', 'total')
            ->get()
            ->sortByDesc('total_profit')
            ->first();

        // Menyusun summary untuk dikirim ke UI React (Profit.jsx)
        $summary = [
            'gross_sales' => (int) $revenueTotal,
            'billed_sales' => (int) $billedTotal,
            'tax_collected' => (int) $taxCollected,
            'app_expenses' => (int) $appExpenseAccount,
            'total_hpp' => (int) $totalHpp,
            'gross_profit' => (int) $grossProfitBeforePlatform,
            'net_revenue' => (int) $netRevenue,
            'operating_expense' => (int) $totalOperatingExpenses,
            'profit_total' => (int) $netProfitFinal,
            'gross_margin' => (int) $grossMargin,
            'stored_profit' => (int) $financials['stored_profit'],
            'profit_variance' => (int) $financials['profit_variance'],
            'net_profit_basis' => $canAllocateOperatingExpenses ? 'period' : 'transaction',
            'orders_count' => (int) $ordersCount,
            'items_sold' => (int) $itemsSold,
            'average_profit' => $ordersCount > 0 ? (int) round($netProfitFinal / $ordersCount) : 0,
            'margin_percentage' => $netRevenue > 0 ? round(($netProfitFinal / $netRevenue) * 100, 2) : 0,
            'best_invoice' => $bestTransaction?->invoice,
            'best_profit' => (int) ($bestTransaction?->total_profit ?? 0),
        ];

        return Inertia::render('Dashboard/Reports/Profit', [
            'transactions' => $transactions,
            'summary' => $summary,
            'filters' => $filters,
            'cashiers' => User::select('id', 'name')->orderBy('name')->get(),
            'customers' => Customer::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Apply table filters
     * FIX: Wajib hanya menampilkan transaksi 'paid' (lunas) agar angka akurat.
     */
    protected function applyFilters($query, array $filters)
    {
        return $query
            // 1. Kunci Utama: Hanya hitung yang sudah LUNAS (Mencegah data pending QRIS masuk)
            ->where('payment_status', 'paid')
            // 2. Kecualikan data yang direfund
            ->where('status', '!=', 'refunded')
            ->when($filters['invoice'] ?? null, fn ($q, $invoice) => $q->where('invoice', 'like', '%'.$invoice.'%'))
            ->when($filters['cashier_id'] ?? null, fn ($q, $cashier) => $q->where('cashier_id', $cashier))
            ->when($filters['customer_id'] ?? null, fn ($q, $customer) => $q->where('customer_id', $customer))
            ->when($filters['start_date'] ?? null, fn ($q, $start) => $q->whereDate('created_at', '>=', $start))
            ->when($filters['end_date'] ?? null, fn ($q, $end) => $q->whereDate('created_at', '<=', $end));
    }
}
