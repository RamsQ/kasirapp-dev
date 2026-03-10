<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\User;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\CarbonPeriod;

class ReportController extends Controller
{
    public function finance(Request $request)
    {
        // 1. Tentukan Range Tanggal & Filter Petugas
        $start  = $request->start_date ?? now()->subDays(6)->format('Y-m-d'); 
        $end    = $request->end_date ?? now()->format('Y-m-d');
        $userId = $request->user_id;

        // --- BASE QUERY TRANSAKSI (Hanya yang PAID/LUNAS) ---
        // Variabel ini digunakan sebagai 'blueprint' untuk query lainnya agar konsisten.
        $baseQuery = DB::table('transactions')
            ->where('payment_status', 'paid')
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->when($userId, function($q) use ($userId) {
                $q->where('cashier_id', $userId);
            });

        // 2. HITUNG OMZET (Total Penjualan Bruto dari transaksi Lunas)
        $revenue = (clone $baseQuery)->sum('grand_total');

        // 3. HITUNG AKUN BEBAN KOMISI APLIKASI (Markup + Fee dari transaksi Lunas)
        $appExpenses = (clone $baseQuery)->sum(DB::raw('total_markup + total_fee'));

        // 4. HITUNG LABA BERSIH PENJUALAN (Dari tabel profits, join transaksi Lunas)
        $netProfitFromSales = DB::table('profits')
            ->join('transactions', 'transactions.id', '=', 'profits.transaction_id')
            ->where('transactions.payment_status', 'paid')
            ->whereDate('transactions.created_at', '>=', $start)
            ->whereDate('transactions.created_at', '<=', $end)
            ->when($userId, function($q) use ($userId) {
                $q->where('transactions.cashier_id', $userId);
            })
            ->sum('profits.total');

        // 5. HITUNG HPP (Modal Pokok) 
        // Rumus: Omzet - Beban Aplikasi - Laba Penjualan
        $totalHpp = (float)$revenue - (float)$appExpenses - (float)$netProfitFromSales;
        
        // Laba Kotor (Gross Profit)
        $grossProfit = (float)$revenue - (float)$totalHpp;

        // 6. LOGIKA LAPORAN PROMOSI & DISKON
        // A. Diskon Item/Grosir (Hanya dari transaksi Lunas)
        $itemPromoStats = DB::table('transaction_details')
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->where('transactions.payment_status', 'paid')
            ->whereDate('transactions.created_at', '>=', $start)
            ->whereDate('transactions.created_at', '<=', $end)
            ->when($userId, function($q) use ($userId) {
                $q->where('transactions.cashier_id', $userId);
            })
            ->select(
                'products.title as product_name',
                DB::raw('SUM(transaction_details.qty) as total_qty'),
                DB::raw('SUM((products.sell_price * transaction_details.qty) - transaction_details.price) as total_discount_value')
            )
            ->groupBy('products.id', 'products.title')
            ->having('total_discount_value', '>', 0)
            ->orderByDesc('total_discount_value')
            ->get();

        // B. Diskon Global (Potongan Langsung di Keranjang dari transaksi Lunas)
        $globalDiscountTotal = (clone $baseQuery)->sum('discount');

        // 7. PEMISAHAN PENDAPATAN TUNAI VS DIGITAL (Untuk Audit Keuangan Lunas)
        $cashRevenue = (clone $baseQuery)
            ->where('payment_method', 'cash')
            ->sum('grand_total');

        $digitalRevenue = (clone $baseQuery)
            ->whereIn('payment_method', ['midtrans', 'xendit', 'qris_manual', 'transfer'])
            ->sum('grand_total');

        // 8. AMBIL LIST PENGELUARAN (Expenses)
        $expensesQuery = Expense::with('user:id,name')
            ->whereBetween('date', [$start, $end]);

        if ($userId) {
            $expensesQuery->where('user_id', $userId);
        }

        $expenses = $expensesQuery->latest()->get();
        
        $expenseFromCash    = $expenses->where('source', 'Kas Laci')->sum('amount');
        $expenseFromCapital = $expenses->where('source', 'Modal Luar')->sum('amount');
        $expenseFromDebt    = $expenses->where('source', 'Hutang Dagang')->sum('amount');
        $totalDebtRepayment = $expenses->where('category', 'Pelunasan Hutang')->sum('amount');
        $operationalExpenses = $expenses->where('category', '!=', 'Pelunasan Hutang')->sum('amount');

        // 9. DATA UNTUK CHART (Trend Omzet Lunas vs Pengeluaran Harian)
        $chartData = [];
        $period = CarbonPeriod::create($start, $end);
        foreach ($period as $date) {
            $d = $date->format('Y-m-d');
            
            $dayRevenue = DB::table('transactions')
                ->where('payment_status', 'paid')
                ->whereDate('created_at', $d)
                ->when($userId, fn($q) => $q->where('cashier_id', $userId))
                ->sum('grand_total');
                
            $dayExpense = DB::table('expenses')
                ->whereDate('date', $d)
                ->when($userId, fn($q) => $q->where('user_id', $userId))
                ->sum('amount');

            $chartData[] = [
                'label'   => $date->format('d M'),
                'revenue' => (int)$dayRevenue,
                'expense' => (int)$dayExpense
            ];
        }

        // 10. LOGIKA NERACA (BALANCE SHEET)
        $inventoryValue = DB::table('products')
            ->where('type', 'single')
            ->select(DB::raw('SUM(stock * buy_price) as total_value'))
            ->first()->total_value ?? 0;

        $topAssets = DB::table('products')
            ->where('type', 'single')
            ->where('stock', '>', 0)
            ->select('title', 'stock', 'buy_price', DB::raw('(stock * buy_price) as total_asset_value'))
            ->orderByDesc('total_asset_value')
            ->limit(10)
            ->get();

        $rawExternalCapital = DB::table('capitals')->sum('amount') ?? 0;
        $currentExternalCapital = $rawExternalCapital - $expenseFromCapital;

        $totalInitialCash = DB::table('shifts')
            ->whereDate('opened_at', '>=', $start)
            ->whereDate('opened_at', '<=', $end)
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->sum('starting_cash');
        
        // Audit Kas Laci: (Awal + Masuk Tunai) - Keluar Tunai
        $cashInDrawer = ($totalInitialCash + $cashRevenue) - $expenseFromCash;

        $historyTotalDebt = DB::table('expenses')->where('source', 'Hutang Dagang')->sum('amount');
        $historyTotalRepayment = DB::table('expenses')->where('category', 'Pelunasan Hutang')->sum('amount');
        $remainingDebt = $historyTotalDebt - $historyTotalRepayment;

        // 11. KALKULASI FINAL LABA RUGI RIIL
        $finalNetProfit = (float)$netProfitFromSales - (float)$operationalExpenses; 

        // 12. LOGIKA ANALISIS WAKTU (JAM TERSIBUK - HANYA TRANSAKSI PAID)
        $hourlyTransactions = (clone $baseQuery)
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(*) as count'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->pluck('count', 'hour')
            ->all();

        $formattedHourly = [];
        for ($i = 0; $i < 24; $i++) {
            $formattedHourly[] = [
                'hour' => str_pad($i, 2, '0', STR_PAD_LEFT) . ':00',
                'count' => $hourlyTransactions[$i] ?? 0
            ];
        }
        
        $staffList = User::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Dashboard/Reports/Finance', [
            'report' => [
                'revenue'            => (int)round($revenue),
                'hpp'                => (int)round($totalHpp),
                'grossProfit'        => (int)round($grossProfit),
                'expenses'           => (int)round($operationalExpenses + $appExpenses),
                'expenseFromCash'    => (int)round($expenseFromCash),
                'expenseFromCapital' => (int)round($expenseFromCapital),
                'expenseFromDebt'    => (int)round($expenseFromDebt),
                'debtRepayment'      => (int)round($totalDebtRepayment),
                'netProfit'          => (int)round($finalNetProfit),
                'expenseList'        => $expenses,
                'chartData'          => $chartData,
                'topAssets'          => $topAssets,
                'summary' => [
                    'app_expenses'   => (int)round($appExpenses),
                    'cash_revenue'   => (int)round($cashRevenue),
                    'digital_revenue'=> (int)round($digitalRevenue),
                    'global_discount'=> (int)round($globalDiscountTotal),
                    'item_promos'    => $itemPromoStats,
                    'total_promo'    => (int)round($itemPromoStats->sum('total_discount_value') + $globalDiscountTotal),
                    'hourly_stats'   => $formattedHourly,
                ],
                'balanceSheet' => [
                    'cash_in_drawer'   => (int)round($cashInDrawer),
                    'external_capital' => (int)round($currentExternalCapital),
                    'inventory_value'  => (int)round($inventoryValue),
                    'accounts_payable' => (int)round($remainingDebt),
                    'total_assets'     => (int)round($cashInDrawer + $currentExternalCapital + $inventoryValue),
                    'retained_earnings'=> (int)round($finalNetProfit),
                ],
                'staffList'          => $staffList,
                'filter' => [
                    'start'   => $start,
                    'end'     => $end,
                    'user_id' => $userId
                ]
            ]
        ]);
    }
}