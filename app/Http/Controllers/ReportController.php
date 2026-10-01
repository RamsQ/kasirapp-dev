<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\StockPurchaseRecorder;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function __construct(protected FinancialReportService $financialReports) {}

    public function finance(Request $request)
    {
        // 1. Tentukan Range Tanggal & Filter Petugas
        $start = $request->start_date ?? now()->subDays(6)->format('Y-m-d');
        $end = $request->end_date ?? now()->format('Y-m-d');
        $userId = $request->user_id;

        // --- BASE QUERY TRANSAKSI (Hanya yang PAID/LUNAS) ---
        // Variabel ini digunakan sebagai 'blueprint' untuk query lainnya agar konsisten.
        $baseQuery = DB::table('transactions')
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->when($userId, function ($q) use ($userId) {
                $q->where('cashier_id', $userId);
            });

        // 2. Hitung seluruh angka laporan dari satu definisi bersama.
        $expenseQuery = Expense::query()
            ->where('category', '!=', 'Pelunasan Hutang')
            ->whereBetween('date', [$start, $end])
            ->when($userId, fn ($query) => $query->where('user_id', $userId));
        $financials = $this->financialReports->summary($baseQuery, $expenseQuery);
        $revenue = $financials['net_revenue'];
        $billedRevenue = $financials['billed_revenue'];
        $taxCollected = $financials['tax_collected'];
        $appExpenses = $financials['app_expenses'];
        $netProfitFromSales = $financials['contribution_profit'];
        $totalHpp = $financials['hpp'];
        $grossProfit = $financials['gross_profit_before_platform'];

        // 6. LOGIKA LAPORAN PROMOSI & DISKON
        // A. Diskon Item/Grosir (Hanya dari transaksi Lunas)
        $itemPromoStats = DB::table('transaction_details')
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->where('transactions.payment_status', 'paid')
            ->where('transactions.status', '!=', 'refunded')
            ->whereDate('transactions.created_at', '>=', $start)
            ->whereDate('transactions.created_at', '<=', $end)
            ->when($userId, function ($q) use ($userId) {
                $q->where('transactions.cashier_id', $userId);
            })
            ->select(
                'products.title as product_name',
                DB::raw('SUM(transaction_details.qty) as total_qty'),
                DB::raw('SUM(CASE WHEN (products.sell_price * transaction_details.qty) - transaction_details.price > 0 THEN (products.sell_price * transaction_details.qty) - transaction_details.price ELSE 0 END) as total_discount_value')
            )
            ->groupBy('products.id', 'products.title')
            ->having('total_discount_value', '>', 0)
            ->orderByDesc('total_discount_value')
            ->get();

        // B. Diskon Global (Potongan Langsung di Keranjang dari transaksi Lunas)
        $globalDiscountTotal = (clone $baseQuery)->sum(DB::raw('COALESCE(discount, 0)'));

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

        // Source yang tidak dikenal tetap dihitung sebagai pengeluaran kas.
        // Lihat Expense::scopeCashOutflow: lebih baik kas terlihat kurang
        // daripada neraca terlihat balance padahal uangnya tidak tercatat.
        $expenseFromCash = $expenses->reject(
            fn ($row) => in_array($row->source, Expense::NOT_A_DRAWER_OUTFLOW, true)
        )->sum('amount');
        $expenseFromCapital = $expenses->where('source', Expense::SOURCE_CAPITAL)->sum('amount');
        $expenseFromDebt = $expenses->where('source', Expense::SOURCE_DEBT)->sum('amount');
        $totalDebtRepayment = $expenses->where('category', 'Pelunasan Hutang')->sum('amount');
        $operationalExpenses = $financials['operating_expenses'];

        // 9. DATA UNTUK CHART (Trend Omzet Lunas vs Pengeluaran Harian)
        $chartData = [];
        $period = CarbonPeriod::create($start, $end);
        foreach ($period as $date) {
            $d = $date->format('Y-m-d');

            $dayRevenue = DB::table('transactions')
                ->where('payment_status', 'paid')
                ->where('status', '!=', 'refunded')
                ->whereDate('created_at', $d)
                ->when($userId, fn ($q) => $q->where('cashier_id', $userId))
                ->sum(DB::raw('COALESCE(taxable_amount, grand_total - COALESCE(tax_amount, 0))'));

            $dayExpense = DB::table('expenses')
                ->whereDate('date', $d)
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->sum('amount');

            $chartData[] = [
                'label' => $date->format('d M'),
                'revenue' => (int) $dayRevenue,
                'expense' => (int) $dayExpense,
            ];
        }

        // =============================================================
        // 10. LOGIKA NERACA (BALANCE SHEET)
        // =============================================================
        // Nilai persediaan harus mencakup BAHAN BAKU, bukan hanya produk jadi.
        // Menu bernilai resep dikecualikan supaya tidak dihitung dua kali: stok
        // menu resep tidak pernah berkurang (ketersediaan diambil dari bahan),
        // jadi nilainya sudah tercakup di persediaan bahan baku.
        $recipeMenuIds = DB::table('recipes')->distinct()->pluck('product_id');

        $productInventoryValue = $this->inventoryValue('products', $recipeMenuIds);
        $ingredientInventoryValue = $this->inventoryValue('ingredients');

        $inventoryValue = $productInventoryValue + $ingredientInventoryValue;

        // Daftar aset terbesar. Menu resep tidak ditampilkan karena nilainya
        // sudah tercakup di bahan, dan supaya tidak terhitung dua kali.
        $topAssets = DB::table('products')
            ->whereNull('deleted_at')
            ->where('stock', '>', 0)
            ->whereNotIn('id', $recipeMenuIds)
            ->select('title', 'stock', 'buy_price', 'cost_price', DB::raw('(stock * COALESCE(NULLIF(cost_price, 0), buy_price, 0)) as total_asset_value'))
            ->get()
            ->map(fn ($row) => (object) [
                'title' => $row->title,
                'type' => 'product',
                'stock' => (float) $row->stock,
                'buy_price' => (float) $row->buy_price,
                'cost_price' => (float) $row->cost_price,
                'total_asset_value' => (float) $row->total_asset_value,
            ])
            ->concat(
                DB::table('ingredients')
                    ->where('stock', '>', 0)
                    ->select('name', 'stock', 'buy_price', DB::raw('(COALESCE(stock, 0) * COALESCE(buy_price, 0)) as total_asset_value'))
                    ->get()
                    ->map(fn ($row) => (object) [
                        'title' => $row->name,
                        'type' => 'ingredient',
                        'stock' => (float) $row->stock,
                        'buy_price' => (float) $row->buy_price,
                        'cost_price' => (float) $row->buy_price,
                        'total_asset_value' => (float) $row->total_asset_value,
                    ])
            )
            ->sortByDesc('total_asset_value')
            ->take(10)
            ->values();

        // 11. KALKULASI FINAL LABA RUGI RIIL
        // Laba ini tetap BERBASIS PERIODE karena dipakai di Laporan Laba Rugi.
        // Neraca memakai $retainedEarnings (kumulatif) di bawah.
        $finalNetProfit = $financials['net_profit'];

        $rawExternalCapital = DB::table('capitals')->sum('amount') ?? 0;
        $currentExternalCapital = $rawExternalCapital - $expenseFromCapital;

        // ============================================================
        // NERACA adalah FOTO pada satu tanggal, bukan arus periode. Semua
        // angka di bawah ini karena itu dikumpulkan kumulatif sampai $end.
        // Sebelumnya kas dihitung per periode sementara utang, pajak, dan
        // modal dihitung sepanjang masa, sehingga "selisih neraca" tidak
        // pernah berarti apa-apa.
        // ============================================================
        $asOf = DB::table('transactions')
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->whereDate('created_at', '<=', $end);

        $allExpenses = DB::table('expenses')->whereDate('date', '<=', $end);
        if ($userId) {
            $asOf->where('cashier_id', $userId);
            $allExpenses->where('user_id', $userId);
        }

        // AKTIVA
        // KAS DI LACI tidak boleh dihitung dari penjumlahan starting_cash
        // semua shift.
        //
        // MASALAH: float itu berputar, bukan tambahan baru. Tutup shift dengan
        // kas Rp 150.000, esoknya buka lagi dengan Rp 50.000 sisa yang
        // dihitung; Rp 100.000 sisanya tetap di laci, bukan hilang. Kalau
        // starting_cash setiap shift dijumlahkan, float itu dihitung berkali-
        // kali dan kas neraca naik tanpa batas, sementara Modal Disetor cuma
        // tercatat sekali.
        //
        // YANG BENAR: laci itu satu wadah yang tidak direset. Jadi basisnya
        // adalah hasil HITUNGAN kas pada shift yang terakhir ditutup, lalu
        // ditambah penjualan dan dikurangi pengeluaran setelah waktu itu.
        $lastClosedShift = DB::table('shifts')
            ->whereNotNull('closed_at')
            ->whereNotNull('total_cash_actual')
            ->whereDate('closed_at', '<=', $end)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->first();

        if ($lastClosedShift) {
            $cashAnchor = (float) $lastClosedShift->total_cash_actual;
            $cashAnchorAt = (string) $lastClosedShift->closed_at;
        } else {
            // Belum ada shift yang ditutup: pakai modal awal shift pertama.
            $firstShift = DB::table('shifts')
                ->whereDate('opened_at', '<=', $end)
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->orderBy('opened_at')
                ->orderBy('id')
                ->first();

            $cashAnchor = (float) ($firstShift->starting_cash ?? 0);
            $cashAnchorAt = (string) ($firstShift->opened_at ?? '1970-01-01 00:00:00');
        }

        // Arus SETELAH basis di atas. Kalau basisnya hasil hitungan shift
        // yang sudah tutup, penjualan sebelum waktu itu sudah terhitung
        // di dalamnya dan tidak boleh ditambah lagi.
        $cashFlowQuery = (clone $asOf)->where('created_at', '>', $cashAnchorAt);
        $cashOutflowQuery = (clone $allExpenses)->where('created_at', '>', $cashAnchorAt);

        $cashSalesAllTime = (clone $cashFlowQuery)->where('payment_method', 'cash')->sum('grand_total');

        // Semua pengeluaran kecuali yang memang bukan outflow laci. Modal Luar
        // termasuk di sini: uangnya benar-benar keluar dari laci, dan sisi
        // ekuitasnya sudah berkurang lewat pengurangan modal, jadi kalau tidak
        // dikurangi dari kas, uang itu hilang dari neraca.
        $cashOutflowAllTime = (clone $cashOutflowQuery)
            ->where(function ($query): void {
                $query->whereNull('source')
                    ->orWhereNotIn('source', Expense::NOT_A_DRAWER_OUTFLOW);
            })
            ->sum('amount');

        // Pinjaman yang dipakai: uang masuk, jadi menambah kas, bukan mengurangi.
        $borrowedInAllTime = (clone $cashOutflowQuery)
            ->where('source', Expense::SOURCE_DEBT)
            ->sum('amount');

        // Setor ke bank: uang pindah dari laci ke rekening. Mengurangi laci,
        // menambah saldo bank di bawah. Dicatat sebagai expense hanya supaya
        // ada tempat mencatatnya, bukan karena ini biaya.
        $bankDepositsAllTime = (clone $allExpenses)
            ->where('source', Expense::SOURCE_BANK_DEPOSIT)
            ->sum('amount');

        // Belanja stok yang dibayar tunai mengurangi kas. Yang dibayar hutang
        // tidak menyentuh kas, tapi menambah hutang usaha di sisi kewajiban.
        // Tanpa pemisahan ini, pembelian stok hanya menaikkan persediaan di
        // sisi aktiva dan selisih neraca akan selalu sebesar nilai stok yang
        // dibeli.
        $stockPurchaseCash = (clone $allExpenses)
            ->where('source', StockPurchaseRecorder::SOURCE)
            ->where('payment_method', StockPurchaseRecorder::PAID_CASH)
            ->sum('amount');

        $stockPurchaseCredit = (clone $allExpenses)
            ->where('source', StockPurchaseRecorder::SOURCE)
            ->where('payment_method', StockPurchaseRecorder::PAID_CREDIT)
            ->sum('amount');

        $cashInDrawer = ($cashAnchor + $borrowedInAllTime + $cashSalesAllTime)
            - $cashOutflowAllTime
            - $stockPurchaseCash
            - $bankDepositsAllTime;

        // Uang NON-TUNAI (QRIS/Midtrans/Xendit/transfer) tinggal di rekening
        // atau di gateway. Sebelumnya jumlah ini tidak pernah masuk aset, jadi
        // setiap penjualan digital hilang dari neraca.
        $digitalSalesAllTime = (clone $asOf)
            ->whereIn('payment_method', ['midtrans', 'xendit', 'qris_manual', 'transfer'])
            ->sum('grand_total');

        $gatewayFeesAllTime = (clone $asOf)->sum(
            DB::raw('COALESCE(total_markup, 0) + COALESCE(total_fee, 0)')
        );

        $bankAndDigital = max(0, $digitalSalesAllTime - $gatewayFeesAllTime) + $bankDepositsAllTime;

        $totalAssets = $cashInDrawer + $bankAndDigital + $inventoryValue;

        // KEWAJIBAN
        $historyTotalDebt = (clone $allExpenses)->where('source', 'Hutang Dagang')->sum('amount');
        $historyTotalRepayment = (clone $allExpenses)->where('category', 'Pelunasan Hutang')->sum('amount');
        $remainingDebt = $historyTotalDebt - $historyTotalRepayment;

        // Stok yang dibeli secara kredit menjadi hutang usaha sampai dibayar.
        // Pelunasan dicatat lewat Expense kategori "Pelunasan Hutang" yang
        // sudah mengurangi total hutang secara umum, jadi tidak perlu
        // mencocokkan pelunasan ke pembelian tertentu.
        $remainingDebt = $historyTotalDebt + max(0, $stockPurchaseCredit) - $historyTotalRepayment;

        // Kewajiban pajak adalah akumulasi sampai tanggal akhir, bukan hanya periode filter.
        $taxPayable = (clone $asOf)->sum(DB::raw('COALESCE(tax_amount, 0)'));
        $totalLiabilities = $remainingDebt + $taxPayable;

        // EKUITAS
        // Modal pemilik adalah setoran pemilik, bukan aset: uangnya sudah ada
        // di Kas di Laci atau di rekening bank. Menghitungnya juga sebagai aset
        // akan menghitung uang yang sama dua kali.
        //
        // Laba ditahan memakai laba kumulatif sampai $end, sama seperti
        // aset. Kalau hanya laba periode, ekuitas akan terlihat anjlok
        // setiap kali periode berganti.
        $cumulativeFinancials = $this->financialReports->summary(
            DB::table('transactions')
                ->where('payment_status', 'paid')
                ->where('status', '!=', 'refunded')
                ->whereDate('created_at', '<=', $end)
                ->when($userId, fn ($query) => $query->where('cashier_id', $userId)),
            Expense::where('category', '!=', 'Pelunasan Hutang')
                ->where('date', '<=', $end)
                ->when($userId, fn ($query) => $query->where('user_id', $userId))
        );

        $retainedEarnings = (float) $cumulativeFinancials['net_profit'];
        $totalEquity = $currentExternalCapital + $retainedEarnings;
        $totalLiabilitiesEquity = $totalLiabilities + $totalEquity;
        $balanceDifference = $totalAssets - $totalLiabilitiesEquity;

        // 12. LOGIKA ANALISIS WAKTU (JAM TERSIBUK - HANYA TRANSAKSI PAID)
        $hourExpression = DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';
        $hourlyTransactions = (clone $baseQuery)
            ->select(DB::raw("{$hourExpression} as hour"), DB::raw('COUNT(*) as count'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->pluck('count', 'hour')
            ->all();

        $formattedHourly = [];
        for ($i = 0; $i < 24; $i++) {
            $formattedHourly[] = [
                'hour' => str_pad($i, 2, '0', STR_PAD_LEFT).':00',
                'count' => $hourlyTransactions[$i] ?? 0,
            ];
        }

        $staffList = User::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Dashboard/Reports/Finance', [
            'report' => [
                'revenue' => (int) round($revenue),
                'billedRevenue' => (int) round($billedRevenue),
                'taxCollected' => (int) round($taxCollected),
                'hpp' => (int) round($totalHpp),
                'grossProfit' => (int) round($grossProfit),
                'contributionProfit' => (int) round($netProfitFromSales),
                'profitVariance' => (int) round($financials['profit_variance']),
                'expenses' => (int) round($operationalExpenses + $appExpenses),
                'expenseFromCash' => (int) round($expenseFromCash),
                'expenseFromCapital' => (int) round($expenseFromCapital),
                'expenseFromDebt' => (int) round($expenseFromDebt),
                'debtRepayment' => (int) round($totalDebtRepayment),
                'netProfit' => (int) round($finalNetProfit),
                'expenseList' => $expenses,
                'chartData' => $chartData,
                'topAssets' => $topAssets,
                'summary' => [
                    'app_expenses' => (int) round($appExpenses),
                    'cash_revenue' => (int) round($cashRevenue),
                    'digital_revenue' => (int) round($digitalRevenue),
                    'tax_collected' => (int) round($taxCollected),
                    'global_discount' => (int) round($globalDiscountTotal),
                    'item_promos' => $itemPromoStats,
                    'total_promo' => (int) round($itemPromoStats->sum('total_discount_value') + $globalDiscountTotal),
                    'hourly_stats' => $formattedHourly,
                ],
                'balanceSheet' => [
                    // AKTIVA
                    'cash_in_drawer' => (int) round($cashInDrawer),
                    'bank_and_digital' => (int) round($bankAndDigital),
                    'inventory_value' => (int) round($inventoryValue),
                    'inventory_ingredients' => (int) round($ingredientInventoryValue),
                    'inventory_products' => (int) round($productInventoryValue),
                    'total_assets' => (int) round($totalAssets),

                    // KEWAJIBAN
                    'accounts_payable' => (int) round($remainingDebt),
                    'tax_payable' => (int) round($taxPayable),
                    'total_liabilities' => (int) round($totalLiabilities),

                    // EKUITAS
                    'external_capital' => (int) round($currentExternalCapital),
                    'retained_earnings' => (int) round($retainedEarnings),
                    'total_equity' => (int) round($totalEquity),

                    'total_liabilities_equity' => (int) round($totalLiabilitiesEquity),
                    'balance_difference' => (int) round($balanceDifference),
                    'net_assets' => (int) round($totalAssets - $totalLiabilities),
                ],
                'staffList' => $staffList,
                'filter' => [
                    'start' => $start,
                    'end' => $end,
                    'user_id' => $userId,
                ],
            ],
        ]);
    }

    /**
     * Nilai persediaan di neraca, dihitung dari LAPISAN BATCH yang benar-benar
     * masih ada, bukan dari `stock x buy_price` master.
     *
     * Kenapa bukan master: buy_price master adalah rata-rata tertimbang,
     * sedangkan HPP penjualan memakai harga batch yang sebenarnya (FIFO/LIFO).
     * Kalau harga beli naik, batch yang tersisa justru yang LAMA dan MURAH,
     * sehingga penilaian rata-rata terlalu rendah dan selisih neraca ikut
     * bergeser.
     *
     * Sumber daya yang belum punya batch (mis. data lama yang diimpor tanpa
     * Stock In) tetap jatuh ke nilai master supaya tidak jadi nol.
     *
     * @param  \Illuminate\Support\Collection<int, int>|null  $excludeProductIds
     */
    private function inventoryValue(string $table, $excludeProductIds = null): float
    {
        $idColumn = $table === 'products' ? 'product_id' : 'ingredient_id';

        $fromBatches = DB::table('stock_batches')
            ->where('qty_remaining', '>', 0)
            ->whereNotNull($idColumn)
            ->groupBy($idColumn)
            ->select($idColumn, DB::raw('SUM(qty_remaining * buy_price) as value'))
            ->pluck('value', $idColumn);

        $query = DB::table($table);
        if ($table === 'products') {
            $query->whereNull('deleted_at');
            if ($excludeProductIds && $excludeProductIds->isNotEmpty()) {
                $query->whereNotIn('id', $excludeProductIds);
            }
        }

        return (float) $query->get()->sum(function ($row) use ($fromBatches, $table) {
            $layered = $fromBatches[$row->id] ?? null;
            if ($layered !== null) {
                return (float) $layered;
            }

            // Tanpa batch: pakai master sebagai cadangan.
            $unitCost = $table === 'products'
                ? (float) ((float) $row->cost_price ?: $row->buy_price)
                : (float) $row->buy_price;

            return (float) $row->stock * $unitCost;
        });
    }
}
