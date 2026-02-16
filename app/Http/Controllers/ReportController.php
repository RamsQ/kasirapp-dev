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

        // 2. HITUNG OMZET (Total Penjualan Bruto)
        $revenue = DB::table('transactions')
            ->where('payment_status', 'paid')
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->sum('grand_total');

        // [BARU] 3. HITUNG AKUN BEBAN KOMISI APLIKASI (Markup + Fee)
        $appExpenses = DB::table('transactions')
            ->where('payment_status', 'paid')
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->sum(DB::raw('total_markup + total_fee'));

        // 4. HITUNG LABA BERSIH PENJUALAN (Sudah dipotong HPP & Markup di TransactionController)
        $netProfitFromSales = DB::table('profits')
            ->join('transactions', 'transactions.id', '=', 'profits.transaction_id')
            ->where('transactions.payment_status', 'paid')
            ->whereDate('transactions.created_at', '>=', $start)
            ->whereDate('transactions.created_at', '<=', $end)
            ->sum('profits.total');

        // 5. HITUNG HPP (Modal) 
        // Rumus: Omzet Bruto - Beban Komisi App - Net Profit Penjualan
        $totalHpp = (float)$revenue - (float)$appExpenses - (float)$netProfitFromSales;
        
        // Laba Kotor (Gross Profit) untuk tampilan tetap menggunakan data murni penjualan
        $grossProfit = (float)$revenue - (float)$totalHpp;

        // 6. AMBIL LIST PENGELUARAN (Expenses) & FILTER SUMBER DANA
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

        // 7. DATA UNTUK CHART (Trend Omzet vs Pengeluaran Harian)
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

        // 8. LOGIKA NERACA (BALANCE SHEET)
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

        // 9. KALKULASI FINAL LABA RUGI RIIL
        // Laba bersih akhir = (Net Profit dari Penjualan) - (Biaya Operasional Toko)
        $finalNetProfit = (float)$netProfitFromSales - (float)$operationalExpenses; 
        
        $staffList = User::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Dashboard/Reports/Finance', [
            'report' => [
                'revenue'            => (int)round($revenue),
                'hpp'                => (int)round($totalHpp),
                'grossProfit'        => (int)round($grossProfit),
                'expenses'           => (int)round($operationalExpenses + $appExpenses), // Gabungan beban operasional + beban app
                'expenseFromCash'    => (int)round($expenseFromCash),
                'expenseFromCapital' => (int)round($expenseFromCapital),
                'expenseFromDebt'    => (int)round($expenseFromDebt),
                'debtRepayment'      => (int)round($totalDebtRepayment),
                'netProfit'          => (int)round($finalNetProfit),
                'expenseList'        => $expenses,
                'chartData'          => $chartData,
                'topAssets'          => $topAssets,
                'summary' => [
                    'app_expenses'   => (int)round($appExpenses), // Dikirim agar muncul di UI
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