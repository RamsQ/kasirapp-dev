<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Transaction;
use App\Services\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Halaman Analitik: ringkasan visual untuk pemilik dan manajer.
 *
 * Halaman ini TIDAK menghitung ulang rumus keuangan. Semua angka diambil dari
 * FinancialReportService lewat AnalyticsService, supaya omzet, HPP, dan laba
 * di sini persis sama dengan Laporan Keuangan untuk rentang tanggal yang sama.
 * Kalau di suatu hari angkanya berbeda, itu bug, bukan perbedaan definisi.
 */
class AnalyticsController extends Controller
{
    public function __construct(protected AnalyticsService $analytics) {}

    /**
     * Periode siap pakai yang bisa dipilih kasir.
     *
     * Nilai kuncinya bukan rentang tanggal, tapi nama preset. Kalau kuncinya
     * tanggal, preset "7 Hari Terakhir" yang disimpan di URL akan ikut
     * bergeser setiap hari lalu membuat halaman itu diam-diam menampilkan
     * periode yang berbeda dari yang diklik.
     */
    private const PRESETS = [
        'today' => ['label' => 'Hari Ini', 'start' => null, 'end' => null, 'days' => 1],
        'yesterday' => ['label' => 'Kemarin', 'start' => null, 'end' => null, 'days' => 1],
        '7d' => ['label' => '7 Hari', 'start' => null, 'end' => null, 'days' => 7],
        '30d' => ['label' => '30 Hari', 'start' => null, 'end' => null, 'days' => 30],
        'this_month' => ['label' => 'Bulan Ini', 'start' => null, 'end' => null, 'days' => null],
        'last_month' => ['label' => 'Bulan Lalu', 'start' => null, 'end' => null, 'days' => null],
        'this_year' => ['label' => 'Tahun Ini', 'start' => null, 'end' => null, 'days' => null],
    ];

    public function index(Request $request)
    {
        [$presetKey, $start, $end] = $this->resolvePeriod($request);

        $days = (int) $start->diffInDays($end) + 1;
        $granularity = $this->resolveGranularity($request, $days);

        // Periode pembanding: rentang dengan panjang sama, tepat sebelum
        // periode utama. Dipakai untuk label naik/turun di KPI dan garis tren.
        $previousEnd = $start->subDay();
        $previousStart = $previousEnd->subDays($days - 1);

        $currentKpis = $this->analytics->kpis(
            $this->transactionQuery($start, $end),
            $this->expenseQuery($start, $end)
        );

        $previousKpis = $this->analytics->kpis(
            $this->transactionQuery($previousStart, $previousEnd),
            $this->expenseQuery($previousStart, $previousEnd)
        );

        $trend = $this->analytics->trend(
            $this->transactionQuery($start, $end),
            $this->expenseQuery($start, $end),
            $granularity,
            $start,
            $end
        );

        $trendQuery = $this->transactionQuery($start, $end);

        return Inertia::render('Dashboard/Analytics/Index', [
            'period' => [
                'preset' => $presetKey,
                'label' => self::PRESETS[$presetKey]['label'] ?? 'Kustom',
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
                'granularity' => $granularity,
                'presets' => collect(self::PRESETS)->map(fn ($preset, $key) => [
                    'key' => $key,
                    'label' => $preset['label'],
                ])->values(),
            ],
            'comparison' => [
                'start' => $previousStart->toDateString(),
                'end' => $previousEnd->toDateString(),
                'days' => $days,
            ],
            'kpis' => $currentKpis,
            'deltas' => $this->deltas($currentKpis, $previousKpis),
            'trend' => $trend,
            'topProducts' => $this->analytics->topProducts($trendQuery, 10),
            'productMargins' => $this->analytics->productMargins($trendQuery, 10),
            'byCategory' => $this->analytics->byCategory($trendQuery),
            'byPaymentMethod' => $this->analytics->byPaymentMethod($trendQuery),
            'byHour' => $this->analytics->byHour($trendQuery),
            'byWeekday' => $this->analytics->byWeekday($trendQuery, $start, $end),
            'byCashier' => $this->analytics->byCashier($trendQuery),
            'lowStock' => $this->analytics->lowStockIngredients(12),
            'promo' => $this->analytics->promoAnalytics($this->transactionQuery($start, $end)),
            'empty' => $currentKpis['transaction_count'] === 0,
        ]);
    }

    /**
     * Tentukan periode dari preset atau rentang kustom.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function resolvePeriod(Request $request): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        $preset = (string) $request->input('preset', '30d');
        $startInput = $request->input('start_date');
        $endInput = $request->input('end_date');

        // Rentang kustom menang kalau kedua tanggal ada. Kalau hanya satu,
        // preset dipakai supaya halaman tidak pernah menampilkan periode
        // dengan awal atau akhir kosong.
        if (! $preset && $startInput && $endInput) {
            $preset = 'custom';
        }

        if ($preset === 'custom') {
            try {
                $start = CarbonImmutable::parse($startInput)->startOfDay();
                $end = CarbonImmutable::parse($endInput)->endOfDay();
            } catch (\Throwable) {
                $preset = '30d';
                $start = $today->subDays(29);
                $end = $today->endOfDay();
            }

            // Tanggal terbalik bukan permintaan yang masuk akal, dan tanpa
            // perbaikan di sini querynya bisa membaca semua data.
            if ($start->greaterThan($end)) {
                [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
            }

            return ['custom', $start, $end];
        }

        if (! array_key_exists($preset, self::PRESETS)) {
            $preset = '30d';
        }

        [$start, $end] = match ($preset) {
            'today' => [$today, $today->endOfDay()],
            'yesterday' => [$today->subDay(), $today->subDay()->endOfDay()],
            '7d' => [$today->subDays(6), $today->endOfDay()],
            '30d' => [$today->subDays(29), $today->endOfDay()],
            'this_month' => [$today->startOfMonth(), $today->endOfDay()],
            'last_month' => [
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_year' => [$today->startOfYear(), $today->endOfDay()],
            default => [$today->subDays(29), $today->endOfDay()],
        };

        return [$preset, $start, $end];
    }

    /**
     * Granularitas dari request, atau dipilih otomatis dari panjang periode.
     */
    private function resolveGranularity(Request $request, int $days): string
    {
        $requested = (string) $request->input('granularity', 'auto');

        if (in_array($requested, AnalyticsService::GRANULARITIES, true)) {
            return $requested;
        }

        return $this->analytics->autoGranularity($days);
    }

    /**
     * Query transaksi untuk satu periode.
     *
     * Sengaja TIDAK memakai validTransactions() di sini. Scope itu dipakai
     * di dalam kpis(), trend(), dan setiap agregasi, supaya tidak ada jalur
     * yang bisa melewatkan transaksi belum lunas atau yang sudah direfund.
     */
    private function transactionQuery(CarbonImmutable $start, CarbonImmutable $end)
    {
        return Transaction::query()
            ->whereDate('created_at', '>=', $start->toDateString())
            ->whereDate('created_at', '<=', $end->toDateString());
    }

    private function expenseQuery(CarbonImmutable $start, CarbonImmutable $end)
    {
        return Expense::query()
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString());
    }

    /**
     * Perubahan tiap KPI dibanding periode sebelumnya, dalam persen.
     *
     * Kalau periode lalu nol, persentasenya tidak dihitung. Membandingkan
     * "Rp 500.000 dari Rp 0" dengan +100% terlihat seperti lompatan besar,
     * padahal belum ada satu pun pembanding sebelumnya. Ditandai null supaya
     * frontend bisa menulis "tidak ada pembanding" sebagai ganti angka
     * yang menyesatkan.
     *
     * @return array<string, float|null>
     */
    private function deltas(array $current, array $previous): array
    {
        $keys = [
            'billed_revenue', 'net_revenue', 'hpp', 'app_expenses',
            'contribution_profit', 'operating_expenses', 'net_profit',
            'transaction_count', 'items_sold', 'average_order',
        ];

        $deltas = [];

        foreach ($keys as $key) {
            $now = (float) ($current[$key] ?? 0);
            $before = (float) ($previous[$key] ?? 0);

            $deltas[$key] = $before != 0.0
                ? round((($now - $before) / abs($before)) * 100, 1)
                : null;
        }

        // Margin dalam poin persen, bukan persen relatif. "Margin naik dari
        // 20% ke 30%" adalah naik 10 poin, bukan naik 50%.
        //
        // Persentase margin selalu ada di kedua sisi, bahkan saat periode
        // pembandingnya nol. Tapi 0% di periode yang tidak ada transaksinya
        // bukan margin sungguhan, itu hanya hasil dari membagi nol dengan nol.
        // Kalau tidak dicek, kartu margin akan menampilkan "naik 7 poin" untuk
        // toko yang bulan lalu tidak berjualan sama sekali, dan itu kesimpulan
        // yang salah.
        $marginNow = $current['margin_percent'] ?? null;
        $marginBefore = $previous['margin_percent'] ?? null;
        $periodBeforeHadSales = (float) ($previous['net_revenue'] ?? 0) > 0;

        $deltas['margin_percent'] = $marginNow !== null
            && $marginBefore !== null
            && $periodBeforeHadSales
                ? round(((float) $marginNow) - (float) $marginBefore, 1)
                : null;

        return $deltas;
    }
}
