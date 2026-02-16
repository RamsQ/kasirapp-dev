<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Profit;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesReportController extends Controller
{
    /**
     * Display the sales report.
     */
    public function index(Request $request)
    {
        $filters = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'invoice' => $request->input('invoice'),
            'cashier_id' => $request->input('cashier_id'),
            'customer_id' => $request->input('customer_id'),
        ];

        // Base query untuk daftar transaksi (mengambil semua kolom termasuk kolom online baru)
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

        // Aggregate query untuk menghitung summary
        $aggregateQuery = $this->applyFilters(Transaction::query(), $filters);

        $totals = (clone $aggregateQuery)
            ->selectRaw('
                COUNT(*) as orders_count,
                COALESCE(SUM(grand_total), 0) as revenue_total,
                COALESCE(SUM(discount), 0) as discount_total,
                COALESCE(SUM(total_markup), 0) as markup_total,
                COALESCE(SUM(total_fee), 0) as fee_total
            ')
            ->first();

        $transactionIds = (clone $aggregateQuery)->pluck('id');

        $itemsSold = $transactionIds->isNotEmpty()
            ? TransactionDetail::whereIn('transaction_id', $transactionIds)->sum('qty')
            : 0;

        $profitTotal = $transactionIds->isNotEmpty()
            ? Profit::whereIn('transaction_id', $transactionIds)->sum('total')
            : 0;

        // Menyusun ringkasan data untuk ditampilkan di Summary Cards
        $summary = [
            'orders_count' => (int) ($totals->orders_count ?? 0),
            'revenue_total' => (int) ($totals->revenue_total ?? 0),
            'discount_total' => (int) ($totals->discount_total ?? 0),
            'items_sold' => (int) $itemsSold,
            'profit_total' => (int) $profitTotal,
            'markup_total' => (int) ($totals->markup_total ?? 0), // Akumulasi Markup %
            'fee_total' => (int) ($totals->fee_total ?? 0),       // Akumulasi Biaya Flat
            'average_order' => ($totals->orders_count ?? 0) > 0
                ? (int) round($totals->revenue_total / $totals->orders_count)
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
     */
    protected function applyFilters($query, array $filters)
    {
        return $query
            ->where('payment_status', '!=', 'refunded')
            ->when($filters['invoice'] ?? null, fn ($q, $invoice) => $q->where('invoice', 'like', '%' . $invoice . '%'))
            ->when($filters['cashier_id'] ?? null, fn ($q, $cashier) => $q->where('cashier_id', $cashier))
            ->when($filters['customer_id'] ?? null, fn ($q, $customer) => $q->where('customer_id', $customer))
            ->when($filters['start_date'] ?? null, fn ($q, $start) => $q->whereDate('created_at', '>=', $start))
            ->when($filters['end_date'] ?? null, fn ($q, $end) => $q->whereDate('created_at', '<=', $end));
    }
}