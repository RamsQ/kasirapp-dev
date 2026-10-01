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

class SalesReportController extends Controller
{
    public function __construct(protected FinancialReportService $financialReports) {}

    /**
     * Display the sales report.
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

        // 1. Base query untuk daftar transaksi
        // Menggunakan applyFilters yang sudah diperkuat (payment_status = paid)
        $baseListQuery = $this->applyFilters(
            Transaction::query()
                ->with(['cashier:id,name', 'customer:id,name'])
                ->withSum('details as total_items', 'qty')
                ->withSum('profits as total_profit', 'total'),
            $filters
        )->orderByDesc('created_at');

        $transactions = (clone $baseListQuery)
            ->paginate(10)
            ->withQueryString();

        // 2. Aggregate query untuk menghitung summary (Hanya transaksi lunas)
        $aggregateQuery = $this->applyFilters(Transaction::query(), $filters);

        $canAllocateOperatingExpenses = ! $filters['invoice'] && ! $filters['customer_id'];
        $expenseQuery = $canAllocateOperatingExpenses
            ? Expense::query()
                ->where('category', '!=', 'Pelunasan Hutang')
                ->when($filters['start_date'], fn ($q, $date) => $q->whereDate('date', '>=', $date))
                ->when($filters['end_date'], fn ($q, $date) => $q->whereDate('date', '<=', $date))
                ->when($filters['cashier_id'], fn ($q, $cashier) => $q->where('user_id', $cashier))
            : null;

        $financials = $this->financialReports->summary($aggregateQuery, $expenseQuery);
        $totals = (clone $aggregateQuery)
            ->selectRaw('
                COALESCE(SUM(COALESCE(discount, 0)), 0) as discount_total,
                COALESCE(SUM(COALESCE(total_markup, 0)), 0) as markup_total,
                COALESCE(SUM(COALESCE(total_fee, 0)), 0) as fee_total
            ')
            ->first();

        // 3. Menyusun ringkasan data untuk Summary Cards
        $summary = [
            'orders_count' => $financials['transaction_count'],
            'revenue_total' => (int) $financials['billed_revenue'],
            'net_sales_total' => (int) $financials['net_revenue'],
            'tax_total' => (int) $financials['tax_collected'],
            'discount_total' => (int) ($totals->discount_total ?? 0),
            'items_sold' => $financials['items_sold'],
            'profit_total' => (int) $financials['contribution_profit'],
            'net_profit' => (int) $financials['net_profit'],
            'operating_expense' => (int) $financials['operating_expenses'],
            'hpp' => (int) $financials['hpp'],
            'profit_variance' => (int) $financials['profit_variance'],
            'net_profit_basis' => $canAllocateOperatingExpenses ? 'period' : 'transaction',
            'markup_total' => (int) ($totals->markup_total ?? 0),
            'fee_total' => (int) ($totals->fee_total ?? 0),
            'average_order' => $financials['transaction_count'] > 0
                ? (int) round($financials['net_revenue'] / $financials['transaction_count'])
                : 0,
        ];

        return Inertia::render('Dashboard/Reports/Sales', [
            'transactions' => $transactions,
            'summary' => $summary,
            'filters' => $filters,
            'cashiers' => User::select('id', 'name')->orderBy('name')->get(),
            'customers' => Customer::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Apply table filters.
     * FIX: Menambahkan filter 'payment_status' = 'paid' agar data pending QRIS tidak masuk laporan.
     */
    protected function applyFilters($query, array $filters)
    {
        return $query
            // KUNCI UTAMA: Hanya tampilkan transaksi yang sudah dibayar (LUNAS)
            ->where('payment_status', 'paid')
            // Tetap mengecualikan transaksi yang direfund
            ->where('status', '!=', 'refunded')
            ->when($filters['invoice'] ?? null, fn ($q, $invoice) => $q->where('invoice', 'like', '%'.$invoice.'%'))
            ->when($filters['cashier_id'] ?? null, fn ($q, $cashier) => $q->where('cashier_id', $cashier))
            ->when($filters['customer_id'] ?? null, fn ($q, $customer) => $q->where('customer_id', $customer))
            ->when($filters['start_date'] ?? null, fn ($q, $start) => $q->whereDate('created_at', '>=', $start))
            ->when($filters['end_date'] ?? null, fn ($q, $end) => $q->whereDate('created_at', '<=', $end));
    }
}
