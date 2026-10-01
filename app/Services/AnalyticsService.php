<?php

namespace App\Services;

use App\Models\Ingredient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agregasi untuk halaman Analitik.
 *
 * Aturan yang dijaga di kelas ini:
 *
 *  1. Angka trend, KPI, dan seluruh panel HPP TIDAK dihitung ulang di sini
 *     dengan rumus sendiri. Lingkup transaksi (hanya yang lunas dan tidak
 *     direfund) dan rumus labanya diambil dari FinancialReportService, sumber
 *     yang sama dengan semua laporan lain. Kalau halaman analitik punya
 *     rumus HPP sendiri, sooner or later angkanya akan beda dari Laporan
 *     Keuangan dan pemakainya akan berhenti mempercayai keduanya.
 *
 *  2. Sumbu waktu selalu lengkap. Bucket dengan nol transaksi tetap muncul
 *     dengan angka nol. Kalau bucket kosong dihilangkan, sumbu X men leaps dan
 *     grafik terlihat seperti tidak ada jeda, padahal tokonya tutup atau
 *     tidak ada yang beli.
 *
 *  3. Beban operasional memakai scope operatingExpense() milik model Expense,
 *     sama persis yang dipakai FinancialReportService.
 */
class AnalyticsService
{
    public const GRANULARITIES = ['daily', 'weekly', 'monthly'];

    /**
     * Batas atas jumlah bucket sebelum(sumbu X jadi impossible dibaca).
     */
    private const DAILY_LIMIT_DAYS = 62;

    private const WEEKLY_LIMIT_DAYS = 400;

    public function __construct(protected FinancialReportService $financials) {}

    /**
     * Granularitas yang masuk akal untuk rentang sepanjang ini.
     *
     * Dipilih otomatis supaya pilihan periode tidak perlu diubah manual
     * setiap kali kasir memperpanjang rentangnya.
     */
    public function autoGranularity(int $days): string
    {
        if ($days <= self::DAILY_LIMIT_DAYS) {
            return 'daily';
        }

        if ($days <= self::WEEKLY_LIMIT_DAYS) {
            return 'weekly';
        }

        return 'monthly';
    }

    /**
     * Pemotongan periode menjadi bucket waktu, lengkap dengan yang kosong.
     *
     * @return Collection<int, array{key: string, label: string}>
     */
    public function spine(CarbonImmutable $start, CarbonImmutable $end, string $granularity): Collection
    {
        $buckets = new Collection;
        $cursor = $this->floorTo($start, $granularity);
        $last = $this->floorTo($end, $granularity);

        // Pengaman terhadap rentang yang salah input (mis. tertukar bulan/tahun).
        // Tanpa ini, loop di bawah bisa jalan sangat lama.
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($last) && $guard < 2000) {
            $buckets->push([
                'key' => $cursor->toDateString(),
                'label' => $this->label($cursor, $granularity),
            ]);

            $cursor = match ($granularity) {
                'weekly' => $cursor->addWeek(),
                'monthly' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };

            $guard++;
        }

        return $buckets;
    }

    /**
     * Angka utama untuk satu periode.
     *
     * Diambil langsung dari FinancialReportService supaya KPI halaman ini
     * identik dengan ringkasan di Laporan Keuangan untuk rentang yang sama.
     *
     * @param  EloquentBuilder  $query  sudah dibatasi tanggal, tanpa filter status
     * @param  EloquentBuilder  $expenseQuery  sudah dibatasi tanggal
     * @return array<string, float|int>
     */
    public function kpis(EloquentBuilder $query, EloquentBuilder $expenseQuery): array
    {
        $summary = $this->financials->summary($query, $expenseQuery);

        $netRevenue = (float) $summary['net_revenue'];

        // Margin dihitung dari omzet bersih, bukan dari laba. Margin atas laba
        // selalu 100% dan tidak pernah menunjukkan apa pun.
        $summary['margin_percent'] = $netRevenue > 0
            ? round(($summary['net_profit'] / $netRevenue) * 100, 1)
            : 0.0;

        $summary['gross_margin_percent'] = $netRevenue > 0
            ? round(($summary['contribution_profit'] / $netRevenue) * 100, 1)
            : 0.0;

        $summary['hpp_percent'] = $netRevenue > 0
            ? round(($summary['hpp'] / $netRevenue) * 100, 1)
            : 0.0;

        $summary['average_order'] = $summary['transaction_count'] > 0
            ? (int) round($netRevenue / $summary['transaction_count'])
            : 0;

        return $summary;
    }

    /**
     * Tren omzet, HPP, dan laba per bucket waktu.
     *
     * @param  EloquentBuilder  $query  sudah dibatasi tanggal
     * @param  EloquentBuilder  $expenseQuery  sudah dibatasi tanggal
     * @param  CarbonImmutable  $start  awal periode yang diminta
     * @param  CarbonImmutable  $end  akhir periode yang diminta
     */
    public function trend(
        EloquentBuilder $query,
        EloquentBuilder $expenseQuery,
        string $granularity,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        // Sumbu dibangun dari rentang yang DIMINTA, bukan dari tanggal transaksi
        // yang kebetulan ada.
        //
        // Kalau dibangun dari data, maka tanggal transaksi pertama dan terakhir
        // jadi ujung sumbu. Untuk periode 7 hari yang isinya cuma 1 transaksi
        // hari ini, sumbunya jadi 1 titik. Grafiknya terlihat seperti "omzet
        // selalu segitu", padahal 6 hari itu memang nol. Memakai rentang
        // yang diminta membuat titik kosong itu tetap terlihat, dan itulah
        // yang membuat grafik bisa dipercaya.
        $spine = $this->spine($start, $end, $granularity);

        $transactionIds = $this->financials->validTransactions(clone $query)->select('transactions.id');
        $dateExpression = $this->dateExpression('t.created_at', $granularity);

        // HPP dibaca dari snapshot buy_price per detail, sama seperti
        // FinancialReportService, bukan dari master.products.cost_price yang
        // hanya perkiraan tampilan.
        $hppPerTransaction = DB::table('transaction_details as details')
            ->selectRaw('details.transaction_id, SUM(COALESCE(details.buy_price, 0) * COALESCE(details.qty, 0)) as hpp')
            ->whereIn('details.transaction_id', clone $transactionIds)
            ->groupBy('details.transaction_id');

        $rows = DB::table('transactions as t')
            ->leftJoinSub($hppPerTransaction, 'd', 'd.transaction_id', '=', 't.id')
            ->whereIn('t.id', $transactionIds)
            ->groupBy(DB::raw($dateExpression))
            ->select([
                DB::raw($dateExpression.' as bucket'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(COALESCE(t.grand_total, 0)) as billed_revenue'),
                DB::raw('SUM(COALESCE(t.taxable_amount, t.grand_total - COALESCE(t.tax_amount, 0))) as net_revenue'),
                DB::raw('SUM(COALESCE(t.tax_amount, 0)) as tax_collected'),
                DB::raw('SUM(COALESCE(t.total_markup, 0) + COALESCE(t.total_fee, 0)) as app_expenses'),
                DB::raw('SUM(COALESCE(d.hpp, 0)) as hpp'),
            ])
            ->get()
            ->keyBy('bucket');

        $expenseDateExpression = $this->dateExpression('date', $granularity);

        $expenseRows = (clone $expenseQuery)
            ->operatingExpense()
            ->groupBy(DB::raw($expenseDateExpression))
            ->select([
                DB::raw($expenseDateExpression.' as bucket'),
                DB::raw('SUM(COALESCE(amount, 0)) as operating_expenses'),
            ])
            ->get()
            ->keyBy('bucket');

        $items = new Collection;

        foreach ($spine as $bucket) {
            $row = $rows->get($bucket['key']);
            $expenseRow = $expenseRows->get($bucket['key']);

            $billed = (float) ($row->billed_revenue ?? 0);
            $net = (float) ($row->net_revenue ?? 0);
            $tax = (float) ($row->tax_collected ?? 0);
            $platform = (float) ($row->app_expenses ?? 0);
            $hpp = (float) ($row->hpp ?? 0);
            $operating = (float) ($expenseRow->operating_expenses ?? 0);
            $orders = (int) ($row->orders ?? 0);

            $gross = $net - $hpp;
            $contribution = $gross - $platform;

            $items->push([
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'orders' => $orders,
                'billed_revenue' => $billed,
                'net_revenue' => $net,
                'tax_collected' => $tax,
                'hpp' => $hpp,
                'gross_profit' => $gross,
                'contribution_profit' => $contribution,
                'operating_expenses' => $operating,
                'net_profit' => $contribution - $operating,
                'margin_percent' => $net > 0 ? round((($contribution - $operating) / $net) * 100, 1) : 0.0,
                'average_order' => $orders > 0 ? (int) round($net / $orders) : 0,
            ]);
        }

        return $items->all();
    }

    /**
     * Produk menurut omzet, untuk panel "Produk Terlaris".
     *
     * Pendapatan baris diambil dari snapshot harga saat transaksi dibuat
     * (price x qty), bukan dari master sell_price sekarang, supaya produk yang
     * harganya naik bulan lalu tidak terlihat seolah harganya naik bulan ini.
     *
     * @param  EloquentBuilder  $query
     * @return array<int, array<string, mixed>>
     */
    public function topProducts(EloquentBuilder $query, int $limit = 10): array
    {
        $transactionIds = $this->financials->validTransactions(clone $query)->select('transactions.id');

        return DB::table('transaction_details as details')
            ->join('products as p', 'p.id', '=', 'details.product_id')
            ->whereIn('details.transaction_id', $transactionIds)
            ->whereNull('p.deleted_at')
            ->groupBy('p.id', 'p.title')
            ->select([
                'p.id',
                'p.title',
                DB::raw('SUM(COALESCE(details.qty, 0)) as qty'),
                DB::raw('SUM(COALESCE(details.price, 0) * COALESCE(details.qty, 0)) as revenue'),
                DB::raw('SUM(COALESCE(details.buy_price, 0) * COALESCE(details.qty, 0)) as hpp'),
            ])
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->decorateMargin($row))
            ->all();
    }

    /**
     * Produk menurut margin, untuk panel "Margin Tertinggi".
     *
     * Diurutkan dari margin, bukan dari omzet, karena dua hal yang berbeda:
     * produk paling laku belum tentu paling menguntungkan, dan membaiknya
     * margin produk yang laku itulah yang paling berdampak.
     *
     * @param  EloquentBuilder  $query
     * @return array<int, array<string, mixed>>
     */
    public function productMargins(EloquentBuilder $query, int $limit = 10): array
    {
        $transactionIds = $this->financials->validTransactions(clone $query)->select('transactions.id');

        $revenue = 'SUM(COALESCE(details.price, 0) * COALESCE(details.qty, 0))';
        $hpp = 'SUM(COALESCE(details.buy_price, 0) * COALESCE(details.qty, 0))';

        return DB::table('transaction_details as details')
            ->join('products as p', 'p.id', '=', 'details.product_id')
            ->whereIn('details.transaction_id', $transactionIds)
            ->whereNull('p.deleted_at')
            // Produk dengan omzet 0 di periode ini tidak punya margin untuk
            // dinilai. Kalau tetap ditampilkan, urutannya ditentukan oleh
            // pembulatan percentage, bukan oleh bisnis.
            ->havingRaw("{$revenue} > 0")
            ->groupBy('p.id', 'p.title')
            ->select([
                'p.id',
                'p.title',
                DB::raw('SUM(COALESCE(details.qty, 0)) as qty'),
                DB::raw("{$revenue} as revenue"),
                DB::raw("{$hpp} as hpp"),
            ])
            // Diurutkan dengan ekspresi, bukan dengan alias `margin_amount`.
            //
            // Alias hasil SELECT tidak bisa dipakai di ORDER BY di MySQL, jadi
            // `orderByDesc('margin_amount')` berakhir sebagai "Unknown column
            // in 'order clause'". Ekspresi yang sama ditulis ulang di sini.
            //
            // Urutannya margin nominal (laba per rupiah), bukan persentase.
            // Persentase: produk murah selalu menang. Margin 5% dari
            // omzet Rp 10.000 lebih besar angkanya dari margin 60% dari omzet
            // Rp 20.000, padahal laba Rp 12.000-lah yang lebih berharga.
            ->orderByRaw("({$revenue}) - ({$hpp}) DESC")
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->decorateMargin($row))
            ->all();
    }

    /**
     * Omzet per kategori produk.
     *
     * @param  EloquentBuilder  $query
     * @return array<int, array<string, mixed>>
     */
    public function byCategory(EloquentBuilder $query): array
    {
        $transactionIds = $this->financials->validTransactions(clone $query)->select('transactions.id');

        $rows = DB::table('transaction_details as details')
            ->join('products as p', 'p.id', '=', 'details.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('details.transaction_id', $transactionIds)
            ->whereNull('p.deleted_at')
            ->groupBy('c.id', 'c.name')
            ->select([
                DB::raw("COALESCE(c.name, 'Tanpa Kategori') as name"),
                DB::raw('SUM(COALESCE(details.qty, 0)) as qty'),
                DB::raw('SUM(COALESCE(details.price, 0) * COALESCE(details.qty, 0)) as revenue'),
                DB::raw('SUM(COALESCE(details.buy_price, 0) * COALESCE(details.qty, 0)) as hpp'),
            ])
            ->orderByDesc('revenue')
            ->get();

        // BUKAN lewat decorateMargin(). Fungsi itu membaca kolom `title` untuk
        // nama, jadi di sini hasilnya selalu jatuh ke "Tanpa Kategori" kalau
        // dipanggil apa adanya. Nama kategori memang tidak ada di decorateMargin
        // karena panel produk memang tidak butuh kolom itu.
        return $rows->map(function ($row) {
            $margin = $this->marginOf((float) $row->revenue, (float) $row->hpp);

            return [
                'name' => $row->name,
                'qty' => (float) $row->qty,
                'revenue' => $margin['revenue'],
                'hpp' => $margin['hpp'],
                'margin_amount' => $margin['amount'],
                'margin_percent' => $margin['percent'],
            ];
        })->all();
    }

    /**
     * Jumlah pesanan dan omzet per metode pembayaran.
     *
     * @param  EloquentBuilder  $query
     * @return array<int, array<string, mixed>>
     */
    public function byPaymentMethod(EloquentBuilder $query): array
    {
        $rows = $this->financials->validTransactions(clone $query)
            ->groupBy('payment_method')
            ->select([
                DB::raw("COALESCE(payment_method, 'tidak diketahui') as method"),
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(COALESCE(grand_total, 0)) as revenue'),
            ])
            ->orderByDesc('revenue')
            ->get();

        return $rows->map(fn ($row) => [
            'method' => $row->method,
            'orders' => (int) $row->orders,
            'revenue' => (float) $row->revenue,
        ])->all();
    }

    /**
     * Jam berapa toko sibuk.
     *
     * Dipakai untuk mengatur shift. Data hanya transaksi yang tercatat pada
     * jam itu; penjualan yang diinput dengan tanggal berbeda tidak ikut
     * dihitung, karena tidak ada cara trustworthy untuk memperbaikinya.
     *
     * @param  EloquentBuilder  $query
     * @return array<int, array<string, mixed>>
     */
    public function byHour(EloquentBuilder $query): array
    {
        $rows = $this->financials->validTransactions(clone $query)
            ->groupBy(DB::raw($this->hourExpression('created_at')))
            ->select([
                DB::raw($this->hourExpression('created_at').' as hour'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(COALESCE(grand_total, 0)) as revenue'),
            ])
            ->orderBy('hour')
            ->get()
            // Kunci dipaksa jadi integer: MySQL mengembalikan angka, SQLite
            // mengembalikan string. Tanpa ini, lookup per jam tidak pernah
            // cocok di SQLite dan semua baris jadi nol.
            ->keyBy(fn ($row) => (int) $row->hour);

        $hours = [];

        for ($hour = 0; $hour <= 23; $hour++) {
            $row = $rows->get($hour);
            $orders = (int) ($row->orders ?? 0);

            $hours[] = [
                'hour' => $hour,
                'label' => sprintf('%02d:00', $hour),
                'orders' => $orders,
                'revenue' => (float) ($row->revenue ?? 0),
                'average_order' => $orders > 0 ? (int) round(((float) $row->revenue) / $orders) : 0,
            ];
        }

        return $hours;
    }

    /**
     * Pola per hari dalam seminggu.
     *
     * Menyenangkan dalam bentuk apa pun selama datanya jujur, jadi selalu
     * dinormalisasi per hari, bukan per minggu.
     *
     * Pembagi jumlah kemunculan setiap hari dalam periode dihitung dari
     * kalender, bukan dari tanggal transaksi yang ada. Kalau pembaginya dari
     * data, maka periode yang hanya berisi transaksi hari Senin akan
     * menampilkan "Senin: 10 pesanan/hari" dan hari lain 0, padahal tokonya
     * buka 7 hari. Rata-rata per hari harus dihitung dari hari yang benar
     *-benar dilewati, termasuk hari yang tutup.
     *
     * @param  EloquentBuilder  $query
     * @return array<int, array<string, mixed>>
     */
    public function byWeekday(
        EloquentBuilder $query,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $rows = $this->financials->validTransactions(clone $query)
            ->groupBy(DB::raw($this->weekdayExpression('created_at')))
            ->select([
                DB::raw($this->weekdayExpression('created_at').' as weekday'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(COALESCE(grand_total, 0)) as revenue'),
            ])
            ->get()
            ->keyBy(fn ($row) => (int) $row->weekday);

        $names = [
            1 => 'Minggu', 2 => 'Senin', 3 => 'Selasa', 4 => 'Rabu',
            5 => 'Kamis', 6 => 'Jumat', 7 => 'Sabtu',
        ];

        $occurrences = $this->weekdayOccurrences($start, $end);

        $weekdays = [];

        // 1 = Minggu; diputar supaya Senin lebih dulu, karena itulah urutan
        // yang dipakai saat menyusun jadwal shift.
        foreach ([2, 3, 4, 5, 6, 7, 1] as $weekday) {
            $row = $rows->get($weekday);
            $orders = (int) ($row->orders ?? 0);
            $revenue = (float) ($row->revenue ?? 0);
            $days = max(1, (int) $occurrences[$weekday]);

            $weekdays[] = [
                'weekday' => $weekday,
                'name' => $names[$weekday],
                'short' => substr($names[$weekday], 0, 3),
                'days' => $days,
                'orders' => $orders,
                'revenue' => $revenue,
                'orders_per_day' => round($orders / $days, 1),
                'revenue_per_day' => (int) round($revenue / $days),
            ];
        }

        return $weekdays;
    }

    /**
     * Berapa kali tiap hari muncul di antara tanggal yang diminta.
     *
     * Menghitung lewat kalender, bukan lewat data transaksi, supaya hari
     * tanpa transaksi ikut dihitung.
     *
     * @return array<int, int> 1=Minggu s/d 7=Sabtu => jumlah kemunculan
     */
    private function weekdayOccurrences(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = array_fill(1, 7, 0);
        $cursor = $start->startOfDay();
        $last = $end->endOfDay();

        // Pengaman terhadap rentang yang tidak wajar. Tanpa ini, rentang
        // beberapa ribu tahun akan membuat loop berjalan lama sekali.
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($last) && $guard < 20000) {
            // Pakai dayOfWeek, BUKAN dayOfWeekIso.
            //
            // dayOfWeek memberi 0=Minggu s/d 6=Sabtu, persis seperti DAYOFWEEK()
            // MySQL yang dipakai menghitung angka di panel. dayOfWeekIso
            // memberi 1=Senin s/d 7=Minggu, jadi mencampur keduanya membuat
            // indeks proyek cuma kebetulan cocok: daftar 90 hari ikut
            // terisi 26 di key 1 (Minggu) dan 0 di key 7 (Sabtu), padahal
            // key 7 harusnya 13. Akibatnya "Sabtu" terlihat 1 hari dan
            // "Minggu" 26 hari, sehingga rata-rata per hari untuk kedua hari
            // itu salah besaran. Totalnya tetap 90, jadi pengecekan jumlah
            // saja tidak akan menangkapnya.
            $counts[$cursor->dayOfWeek + 1]++;
            $cursor = $cursor->addDay();
            $guard++;
        }

        return $counts;
    }

    /**
     * Performa per kasir.
     *
     * HPP dihitung tepat per kasir, dengan menjumlahkan detail barisan
     * transaksi milik kasir itu. Bukan dibagi rata dari total HPP: membagi
     * rata membuat kasir dengan satu transaksi besar terlihat sama saja dengan
     * kasir yang selling terus, dan panel ini justru
     * dipakai untuk menilai performa.
     *
     * Yang ditampilkan tetap laba kontribusi. Beban operasional tidak
     * dialokasikan ke kasir karena tidak ada catatan yang bisa adil
     * mengatribusikannya, dan membaginya asal-asalan akan membuat terlihat
     * seperti kinerja kasir yang sebenarnya tidak.
     *
     * @param  EloquentBuilder  $query
     * @return array<int, array<string, mixed>>
     */
    public function byCashier(EloquentBuilder $query): array
    {
        $transactionIds = $this->financials->validTransactions(clone $query)->select('transactions.id');

        $rows = DB::table('transactions as t')
            ->join('users as u', 'u.id', '=', 't.cashier_id')
            ->leftJoinSub(
                DB::table('transaction_details as details')
                    ->select('details.transaction_id')
                    ->selectRaw('SUM(COALESCE(details.buy_price, 0) * COALESCE(details.qty, 0)) as hpp')
                    ->whereIn('details.transaction_id', clone $transactionIds)
                    ->groupBy('details.transaction_id'),
                'hpp',
                'hpp.transaction_id',
                '=',
                't.id'
            )
            ->whereIn('t.id', $transactionIds)
            ->groupBy('u.id', 'u.name')
            ->select([
                'u.id',
                'u.name',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(COALESCE(t.taxable_amount, t.grand_total - COALESCE(t.tax_amount, 0))) as net_revenue'),
                DB::raw('SUM(COALESCE(t.total_markup, 0) + COALESCE(t.total_fee, 0)) as app_expenses'),
                DB::raw('SUM(COALESCE(hpp.hpp, 0)) as hpp'),
            ])
            ->orderByDesc('net_revenue')
            ->get();

        return $rows->map(function ($row) {
            $net = (float) $row->net_revenue;
            $platform = (float) $row->app_expenses;
            $contribution = $net - (float) $row->hpp - $platform;
            $orders = (int) $row->orders;

            return [
                'id' => $row->id,
                'name' => $row->name,
                'orders' => $orders,
                'net_revenue' => $net,
                'average_order' => $orders > 0 ? (int) round($net / $orders) : 0,
                'hpp' => (float) $row->hpp,
                'contribution_profit' => $contribution,
                'margin_percent' => $net > 0 ? round(($contribution / $net) * 100, 1) : 0.0,
            ];
        })->all();
    }

    /**
     * Analitik promosi: berapa yang diberikan away, dan apa akibatnya.
     *
     * BATAS YANG TIDAK BOLEH DILEWATI di sini:
     *
     *  1. TIDAK ADA perbandingan "promo berhasil / gagal". Transaksi yang
     *     memakai promo dipilih pelanggan yang sudah tahu promo atau punya
     *     voucher, jadi rata-rata order-nya memang lebih tinggi sejak awal.
     *     Selisih antara kelompok itu bukan efek promo, itu efek siapa yang
     *     memilih memakai promo. Jadi yang ditampilkan hanya PERBANDINGAN,
     *     dan labelnya jujur: bukan "promo menaikkan omzet".
     *
     *  2. TIDAK bisa diurutkan per aturan promo. `transactions` menyimpan
     *     `discount` sebagai nominal, tidak menyimpan `discount_id`. Jadi
     *     transaksi yang dapat diskon global tidak bisa ditelusuri ke baris
     *     `discounts` mana yang memberikannya. Yang bisa dilacak hanya
     *     voucher, lewat `voucher_code` yang memang tersimpan per transaksi.
     *
     *  3. Basis untuk "seberapa besar diskonnya" adalah omzet SEBELUM
     *     diskon, yaitu taxable_amount + discount. Kalau memakai
     *     taxable_amount saja, diskon akan terlihat jauh lebih kecil dari
     *     kenyataan karena omzet yang dibandingkan sudah dikurangi diskon.
     *
     * @param  EloquentBuilder  $query
     * @return array<string, mixed>
     */
    public function promoAnalytics(EloquentBuilder $query): array
    {
        $valid = $this->financials->validTransactions(clone $query);

        $totals = (clone $valid)
            ->selectRaw('
                COUNT(*) as orders,
                SUM(COALESCE(discount, 0)) as total_discount,
                SUM(COALESCE(voucher_discount, 0)) as voucher_discount,
                SUM(COALESCE(grand_total, 0)) as billed_revenue,
                SUM(COALESCE(taxable_amount, grand_total - COALESCE(tax_amount, 0))) as net_revenue
            ')
            ->first();

        $totalDiscount = (float) ($totals->total_discount ?? 0);
        $voucherDiscount = (float) ($totals->voucher_discount ?? 0);
        $globalDiscount = max(0.0, $totalDiscount - $voucherDiscount);
        $netRevenue = (float) ($totals->net_revenue ?? 0);

        // Omzet sebelum diskon. taxable_amount sudah neto dari diskon, jadi
        // ditambahkan kembali supaya diskon dibandingkan terhadap harga yang
        // benar-benar pernah dibayar pelanggan.
        $revenueBeforeDiscount = $netRevenue + $totalDiscount;

        // Sisa diskon setelah dipotong bagian voucher: itu diskon otomatis
        // dari aturan global.
        $autoDiscount = max(0.0, $totalDiscount - $voucherDiscount);

        /*
         * SETIAP kondisi di-clone dari $valid.
         *
         * Eloquent Builder bisa dimutasi: `->where()` menambah kondisi ke
         * objek yang sama dan mengembalikannya. Kalau $valid dipakai berulang
         * tanpa clone, kondisi menumpuk: `discount > 0` lalu `discount <= 0`
         * menjadi `discount > 0 AND discount <= 0`, yang hasilnya nol. Gejalanya
         * kelompok "tanpa promo" selalu kosong padahal ada 127 transaksi
         * tanpa diskon, dan daftar voucher ikut hilang karena kondisinya
         * mewarisi penumpukan yang sama.
         */
        $withPromo = $this->promoGroup((clone $valid)->where('discount', '>', 0));
        $withoutPromo = $this->promoGroup((clone $valid)->where('discount', '<=', 0));

        return [
            'total_discount' => $totalDiscount,
            'global_discount' => $globalDiscount,
            'voucher_discount' => $voucherDiscount,
            'revenue_before_discount' => $revenueBeforeDiscount,
            'discount_percent_of_revenue' => $revenueBeforeDiscount > 0
                ? round(($totalDiscount / $revenueBeforeDiscount) * 100, 1)
                : 0.0,
            'with_promo' => $withPromo,
            'without_promo' => $withoutPromo,
            'vouchers' => $this->voucherBreakdown(
                (clone $valid)->whereNotNull('voucher_code')->where('voucher_code', '!=', '')
            ),
            'promo_orders' => (int) ($withPromo['orders'] ?? 0),
            'total_orders' => (int) ($totals->orders ?? 0),
        ];
    }

    /**
     * Ringkasan satu kelompok transaksi (berpromo atau tidak).
     *
     * HPP dihitung persis seperti di FinancialReportService: jumlahkan
     * buy_price x qty dari transaction_details. Disederhanakan dengan
     * menggabungkan detail ke transaksi lewat join subquery supaya tidak
     * ada N+1 dan tidak perlu menghitung ulang per baris di PHP.
     *
     * @return array<string, float|int>
     */
    private function promoGroup(EloquentBuilder $query): array
    {
        $hppSub = DB::table('transaction_details as details')
            ->select('details.transaction_id')
            ->selectRaw('SUM(COALESCE(details.buy_price, 0) * COALESCE(details.qty, 0)) as hpp')
            ->groupBy('details.transaction_id');

        /*
         * Subquery harus memuat SEMUA kolom yang dijumlahkan di luar, bukan
         * hanya id. Kalau cuma `select transactions.id`, alias `t` di query
         * luar tidak punya kolom discount / grand_total / taxable_amount, dan
         * MySQL gagal dengan "Unknown column 't.discount' in 'field list'".
         */
        $inner = (clone $query)
            ->select('transactions.id')
            ->addSelect([
                'transactions.discount',
                'transactions.grand_total',
                'transactions.taxable_amount',
                'transactions.tax_amount',
            ])
            ->toBase();

        $row = DB::query()
            ->fromSub($inner, 't')
            ->leftJoinSub($hppSub, 'd', 'd.transaction_id', '=', 't.id')
            ->selectRaw('
                COUNT(*) as orders,
                SUM(COALESCE(t.discount, 0)) as discount,
                SUM(COALESCE(t.grand_total, 0)) as revenue,
                SUM(COALESCE(t.taxable_amount, t.grand_total - COALESCE(t.tax_amount, 0))) as net_revenue,
                SUM(COALESCE(d.hpp, 0)) as hpp
            ')
            ->first();

        $orders = (int) ($row->orders ?? 0);
        $net = (float) ($row->net_revenue ?? 0);
        $hpp = (float) ($row->hpp ?? 0);

        return [
            'orders' => $orders,
            'discount' => (float) ($row->discount ?? 0),
            'revenue' => (float) ($row->revenue ?? 0),
            'net_revenue' => $net,
            'hpp' => $hpp,
            'gross_profit' => $net - $hpp,
            'average_order' => $orders > 0 ? (int) round($net / $orders) : 0,
            'average_discount' => $orders > 0 ? (int) round(((float) ($row->discount ?? 0)) / $orders) : 0,
        ];
    }

    /**
     * Pecah voucher yang terpakai, satu baris per kode.
     *
     * Ini satu-satunya promo yang bisa dilacak per aturannya, karena
     * `voucher_code` memang disimpan di tiap transaksi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function voucherBreakdown(EloquentBuilder $query, int $limit = 8): array
    {
        return (clone $query)
            ->groupBy('voucher_code')
            ->select([
                'voucher_code',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(COALESCE(voucher_discount, 0)) as discount'),
                DB::raw('SUM(COALESCE(grand_total, 0)) as revenue'),
                DB::raw('SUM(COALESCE(taxable_amount, grand_total - COALESCE(tax_amount, 0))) as net_revenue'),
            ])
            ->orderByDesc('orders')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'code' => $row->voucher_code,
                'orders' => (int) $row->orders,
                'discount' => (float) $row->discount,
                'revenue' => (float) $row->revenue,
                'net_revenue' => (float) $row->net_revenue,
                'average_order' => (int) $row->orders > 0
                    ? (int) round(((float) $row->net_revenue) / (int) $row->orders)
                    : 0,
            ])
            ->all();
    }

    /**
     * Bahan baku yang sudah menyentuh atau melewati batas minimum.
     *
     * Hanya bahan baku. Produk jadi tidak punya min_stock, jadi "stok menipis"
     * untuk produk jadi tidak punya angka pembanding yang bisa dipercaya, dan
     * menampilkannya sebagai peringatan hanya menambah noise.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lowStockIngredients(int $limit = 12): array
    {
        return Ingredient::query()
            ->whereNotNull('min_stock')
            ->whereColumn('stock', '<=', 'min_stock')
            ->orderByRaw('CASE WHEN min_stock > 0 THEN stock / min_stock ELSE 999 END ASC')
            ->limit($limit)
            ->get(['id', 'name', 'unit', 'stock', 'min_stock', 'buy_price'])
            ->map(fn ($ingredient) => [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'unit' => $ingredient->unit,
                'stock' => (float) $ingredient->stock,
                'min_stock' => (float) $ingredient->min_stock,
                'ratio' => $ingredient->min_stock > 0
                    ? round(($ingredient->stock / $ingredient->min_stock) * 100, 0)
                    : 0.0,
                'shortfall' => max(0, (float) $ingredient->min_stock - (float) $ingredient->stock),
                'buy_price' => (float) $ingredient->buy_price,
            ])
            ->all();
    }

    /**
     * Label sumbu X yang enak dibaca untuk bucket ini.
     */
    private function label(CarbonImmutable $date, string $granularity): string
    {
        return match ($granularity) {
            'monthly' => $date->format('M Y'),
            'weekly' => $date->format('d M'),
            default => $date->format('d M'),
        };
    }

    /**
     * Ekspresi SQL yang membulatkan tanggal ke awal bucket.
     *
     * Ditulis dua kali, untuk MySQL dan SQLite. Suite test proyek ini jalan
     * di SQLite in-memory, jadi kalau hanya ada varian MySQL, seluruh halaman
     * Analitik tidak akan bisa diuji sama sekali dan rumus trennya hanya
     * bisa diverifikasi lewat mata di browser.
     */
    private function dateExpression(string $column, string $granularity): string
    {
        if ($this->isSqlite()) {
            return match ($granularity) {
                'monthly' => "date({$column}, 'start of month')",
                // strftime('%w') memberi 0=Minggu, jadi (n + 6) % 7 menghasilkan
                // jumlah hari sejak Senin. 0->6, 1->0, 2->1, ... 6->5.
                'weekly' => "date({$column}, '-' || ((CAST(strftime('%w', {$column}) AS INTEGER) + 6) % 7) || ' days')",
                default => "date({$column})",
            };
        }

        return match ($granularity) {
            'monthly' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            // WEEKDAY() 0 = Senin, jadi dikurangi sampai hari Senin.
            'weekly' => "DATE(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY))",
            default => "DATE({$column})",
        };
    }

    /**
     * Ekspresi jam dalam format 0-23, sama untuk kedua driver.
     */
    private function hourExpression(string $column): string
    {
        return $this->isSqlite()
            ? "CAST(strftime('%H', {$column}) AS UNSIGNED)"
            : "HOUR({$column})";
    }

    /**
     * Ekspresi hari dalam seminggu dengan nilai 1=Minggu s/d 7=Sabtu.
     *
     * Angka MySQL dipakai sebagai acuan supaya panel bisa diurutkan dengan
     * indeks array yang sama di kedua driver.
     */
    private function weekdayExpression(string $column): string
    {
        // strftime('%w') 0=Minggu s/d 6=Sabtu, jadi ditambah satu untuk
        // mengikuti penomoran DAYOFWEEK.
        return $this->isSqlite()
            ? "(CAST(strftime('%w', {$column}) AS INTEGER) + 1)"
            : "DAYOFWEEK({$column})";
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * Samakan tanggal ke awal bucket-nya.
     */
    private function floorTo(CarbonImmutable $date, string $granularity): CarbonImmutable
    {
        return match ($granularity) {
            'monthly' => $date->startOfMonth(),
            'weekly' => $date->startOfWeek(CarbonImmutable::MONDAY),
            default => $date->startOfDay(),
        };
    }

    /**
     * Tambah rasio laba kotor ke baris agregat produk.
     */
    private function decorateMargin(object $row): array
    {
        $margin = $this->marginOf((float) $row->revenue, (float) $row->hpp);

        return [
            'id' => $row->id ?? null,
            'title' => $row->title ?? '-',
            'qty' => (float) $row->qty,
            'revenue' => $margin['revenue'],
            'hpp' => $margin['hpp'],
            'margin_amount' => $margin['amount'],
            'margin_percent' => $margin['percent'],
        ];
    }

    /**
     * Hitung margin dari omzet dan HPP.
     *
     * Omzet nol menghasilkan margin nol, bukan pembagian dengan nol. Panel
     * margin menyaring produk tanpa omzet sebelum tampil, tapi calculate
     * tetap dibuat aman supaya tidak ada caller yang bisa memicu error.
     *
     * @return array{revenue: float, hpp: float, amount: float, percent: float}
     */
    private function marginOf(float $revenue, float $hpp): array
    {
        return [
            'revenue' => $revenue,
            'hpp' => $hpp,
            'amount' => $revenue - $hpp,
            'percent' => $revenue > 0 ? round((($revenue - $hpp) / $revenue) * 100, 1) : 0.0,
        ];
    }

    /**
     * Ambil batas bawah periode dari query yang sudah dibatasi tanggal.
     */
    private function startOf($query): CarbonImmutable
    {
        $min = (clone $query)->min('created_at');

        return $min
            ? CarbonImmutable::parse($min)->startOfDay()
            : CarbonImmutable::now()->startOfMonth();
    }

    private function endOf($query): CarbonImmutable
    {
        $max = (clone $query)->max('created_at');

        return $max
            ? CarbonImmutable::parse($max)->endOfDay()
            : CarbonImmutable::now()->endOfDay();
    }
}
