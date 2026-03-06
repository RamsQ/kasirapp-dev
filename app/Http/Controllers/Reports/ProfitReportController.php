<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Profit;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Expense; // Import Model Expense
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProfitReportController extends Controller
{
    /**
     * Display the profit report.
     */
    public function index(Request $request)
    {
        $filters = [
            'start_date' => $request->input('start_date'),
            'end_date'   => $request->input('end_date'),
            'invoice'    => $request->input('invoice'),
            'cashier_id' => $request->input('cashier_id'),
            'customer_id'=> $request->input('customer_id'),
        ];

        // Query dasar transaksi (Sudah difilter agar mengecualikan status 'refunded')
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

        $transactionIds = (clone $baseQuery)->pluck('id');

        // 2. PENJUALAN BRUTO (Omzet Kotor dari transaksi yang valid)
        $revenueTotal = (clone $baseQuery)->sum('grand_total');

        // 3. BEBAN KOMISI APLIKASI
        $appExpenseAccount = (clone $baseQuery)->sum(DB::raw('total_markup + total_fee'));

        // 4. MARGIN KOTOR (Profit dari Penjualan Produk sebelum dipotong beban operasional)
        // Kita hanya mengambil profit dari ID transaksi yang statusnya bukan refunded
        $grossMargin = $transactionIds->isNotEmpty()
            ? Profit::whereIn('transaction_id', $transactionIds)->sum('total')
            : 0;

        // 5. HARGA POKOK PENJUALAN (HPP / Modal Produk)
        $totalHpp = $revenueTotal - $appExpenseAccount - $grossMargin;

        // 6. TOTAL BEBAN OPERASIONAL (Expenses / Kas Keluar)
        $totalOperatingExpenses = Expense::query()
            ->when($filters['start_date'], fn ($q, $start) => $q->whereDate('date', '>=', $start))
            ->when($filters['end_date'], fn ($q, $end) => $q->whereDate('date', '<=', $end))
            ->when(!$filters['start_date'] && !$filters['end_date'], fn ($q) => $q->whereDate('date', now()->format('Y-m-d')))
            ->sum('amount');

        // 7. LABA BERSIH FINAL (Margin Kotor - Beban Operasional)
        $netProfitFinal = $grossMargin - $totalOperatingExpenses;

        // 8. PENDAPATAN BERSIH TOKO (Uang masuk riil setelah dipotong komisi aplikasi)
        $netRevenue = $revenueTotal - $appExpenseAccount;

        $ordersCount = (clone $baseQuery)->count();

        $itemsSold = $transactionIds->isNotEmpty()
            ? TransactionDetail::whereIn('transaction_id', $transactionIds)->sum('qty')
            : 0;

        // Ambil transaksi terbaik berdasarkan profit terbesar (hanya transaksi valid)
        $bestTransaction = (clone $baseQuery)
            ->withSum('profits as total_profit', 'total')
            ->get()
            ->sortByDesc('total_profit')
            ->first();

        // Menyusun summary untuk dikirim ke UI React (Profit.jsx)
        $summary = [
            'gross_sales'      => (int) $revenueTotal,
            'app_expenses'     => (int) $appExpenseAccount,
            'total_hpp'        => (int) $totalHpp,
            'net_revenue'      => (int) $netRevenue,
            'operating_expense'=> (int) $totalOperatingExpenses, 
            'profit_total'     => (int) $netProfitFinal, 
            'gross_margin'     => (int) $grossMargin, 
            'orders_count'     => (int) $ordersCount,
            'items_sold'       => (int) $itemsSold,
            'average_profit'   => $ordersCount > 0 ? (int) round($netProfitFinal / $ordersCount) : 0,
            'margin_percentage'=> $netRevenue > 0 ? round(($netProfitFinal / $netRevenue) * 100, 2) : 0,
            'best_invoice'     => $bestTransaction?->invoice,
            'best_profit'      => (int) ($bestTransaction?->total_profit ?? 0),
        ];

        return Inertia::render('Dashboard/Reports/Profit', [
            'transactions' => $transactions,
            'summary'      => $summary,
            'filters'      => $filters,
            'cashiers'     => User::select('id', 'name')->orderBy('name')->get(),
            'customers'    => Customer::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Apply table filters
     * Diperbarui untuk menggunakan kolom 'status' demi akurasi Laporan Keuntungan.
     */
    protected function applyFilters($query, array $filters)
    {
        return $query
            // Pastikan data refund dikeluarkan dari perhitungan Laba/Rugi
            ->where('status', '!=', 'refunded')
            ->when($filters['invoice'] ?? null, fn ($q, $invoice) => $q->where('invoice', 'like', '%' . $invoice . '%'))
            ->when($filters['cashier_id'] ?? null, fn ($q, $cashier) => $q->where('cashier_id', $cashier))
            ->when($filters['customer_id'] ?? null, fn ($q, $customer) => $q->where('customer_id', $customer))
            ->when($filters['start_date'] ?? null, fn ($q, $start) => $q->whereDate('created_at', '>=', $start))
            ->when($filters['end_date'] ?? null, fn ($q, $end) => $q->whereDate('created_at', '<=', $end));
    }
}