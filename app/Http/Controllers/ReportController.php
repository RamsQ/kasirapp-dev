<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\User;
use App\Models\Setting;
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

        // 2. HITUNG OMZET (Total Penjualan)
        $revenue = DB::table('transactions')
            ->where('payment_status', 'paid')
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->sum('grand_total');

        // 3. HITUNG LABA KOTOR (GROSS PROFIT) - SINKRONISASI TOTAL
        // Mengambil data langsung dari tabel profits agar sama dengan laporan produk
        $grossProfit = DB::table('profits')
            ->join('transactions', 'transactions.id', '=', 'profits.transaction_id')
            ->where('transactions.payment_status', 'paid')
            ->whereDate('transactions.created_at', '>=', $start)
            ->whereDate('transactions.created_at', '<=', $end)
            ->sum('profits.total');

        // Hitung HPP (Modal) berdasarkan selisih Omzet dan Laba Kotor
        // Menggunakan round() untuk menghindari selisih 1 perak akibat floating point
        $totalHpp = (float)$revenue - (float)$grossProfit;

        // 4. AMBIL LIST PENGELUARAN (Expenses) & FILTER SUMBER DANA
        $expensesQuery = Expense::with('user:id,name')
            ->whereBetween('date', [$start, $end]);

        if ($userId) {
            $expensesQuery->where('user_id', $userId);
        }

        $expenses = $expensesQuery->latest()->get();
        
        // Pemisahan pengeluaran berdasarkan sumber dana
        $expenseFromCash    = $expenses->where('source', 'Kas Laci')->sum('amount');
        $expenseFromCapital = $expenses->where('source', 'Modal Luar')->sum('amount');
        $expenseFromDebt    = $expenses->where('source', 'Hutang Dagang')->sum('amount');
        
        $totalDebtRepayment = $expenses->where('category', 'Pelunasan Hutang')->sum('amount');
        
        // Total Biaya Operasional Riil (Tanpa pelunasan hutang)
        $operationalExpenses = $expenses->where('category', '!=', 'Pelunasan Hutang')->sum('amount');

        // 5. DATA UNTUK CHART (Trend Omzet vs Pengeluaran Harian)
        $chartData = [];
        $period = CarbonPeriod::create($start, $end);
        foreach ($period as $date) {
            $d = $date->format('Y-m-d');
            $dayRevenue = DB::table('transactions')->whereDate('created_at', $d)->where('payment_status', 'paid')->sum('grand_total');
            $dayExpense = DB::table('expenses')->whereDate('date', $d)->sum('amount');

            $chartData[] = [
                'label'   => $date->format('d M'),
                'revenue' => (int)$dayRevenue,
                'expense' => (int)$dayExpense
            ];
        }

        // 6. LOGIKA NERACA (BALANCE SHEET)
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
            ->sum('starting_cash');
        
        $cashInDrawer = ($totalInitialCash + $revenue) - $expenseFromCash;

        $historyTotalDebt = DB::table('expenses')->where('source', 'Hutang Dagang')->sum('amount');
        $historyTotalRepayment = DB::table('expenses')->where('category', 'Pelunasan Hutang')->sum('amount');
        $remainingDebt = $historyTotalDebt - $historyTotalRepayment;

        // 7. KALKULASI FINAL LABA RUGI
        // Net Profit = Gross Profit - Operasional
        $netProfit = (float)$grossProfit - (float)$operationalExpenses; 
        
        $staffList = User::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Dashboard/Reports/Finance', [
            'report' => [
                'revenue'            => (int)round($revenue),
                'hpp'                => (int)round($totalHpp),
                'grossProfit'        => (int)round($grossProfit),
                'expenses'           => (int)round($operationalExpenses),
                'expenseFromCash'    => (int)round($expenseFromCash),
                'expenseFromCapital' => (int)round($expenseFromCapital),
                'expenseFromDebt'    => (int)round($expenseFromDebt),
                'debtRepayment'      => (int)round($totalDebtRepayment),
                'netProfit'          => (int)round($netProfit),
                'expenseList'        => $expenses,
                'chartData'          => $chartData,
                'topAssets'          => $topAssets,
                'balanceSheet' => [
                    'cash_in_drawer'   => (int)round($cashInDrawer),
                    'external_capital' => (int)round($currentExternalCapital),
                    'inventory_value'  => (int)round($inventoryValue),
                    'accounts_payable' => (int)round($remainingDebt),
                    'total_assets'     => (int)round($cashInDrawer + $currentExternalCapital + $inventoryValue),
                    'retained_earnings'=> (int)round($netProfit),
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