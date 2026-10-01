<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\FinancialReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Halaman Analitik.
 *
 * Yang dijaga di sini, urut dari yang paling damaging:
 *
 *  1. Angka halaman Analitik HARUS sama persis dengan FinancialReportService
 *     untuk rentang yang sama. Kalau tidak, pemilik akan melihat dua angka
 *     laba yang berbeda di dua halaman dan tidak tahu mana yang benar.
 *
 *  2. Hanya transaksi lunas yang dihitung. Transaksi QRIS yang masih
 *     pending dan transaksi yang sudah direfund tidak boleh muncul, karena
 *     keduanya bikin laba terlihat lebih bagus dari kenyataan.
 *
 *  3. Sumbu waktu harus lengkap. Hari tanpa transaksi tetap muncul dengan
 *     angka nol, kalau tidak grafik akan terlihat tidak ada jeda.
 *
 *  4. Periode pembanding harus tepat sepanjang periode utama.
 */
class UatAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'analytics-access', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['email' => 'admin@gmail.com', 'name' => 'Kasir Uji']);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->admin->givePermissionTo('analytics-access');

        Setting::create([
            'cogs_method' => 'FIFO',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'kitchen_display_enabled' => false,
        ]);

        $this->category = Category::create(['name' => 'Makanan']);
    }

    protected function product(string $title, int $sellPrice, int $buyPrice = 0): Product
    {
        return Product::create([
            'category_id' => $this->category->id,
            'title' => $title,
            'type' => 'product',
            'sell_price' => $sellPrice,
            'buy_price' => $buyPrice,
            'stock' => 100,
        ]);
    }

    /**
     * Buat transaksi langsung di database, supaya HPP bisa diisi dengan nilai
     * yang disengaja tanpa harus menjalankan seluruh alur kasir.
     */
    protected function transaction(array $attributes = [], array $lines = []): Transaction
    {
        $defaults = [
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-'.uniqid(),
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 0,
            'taxable_amount' => 0,
            'tax_amount' => 0,
            'total_markup' => 0,
            'total_fee' => 0,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ];

        $transaction = new Transaction(array_merge($defaults, $attributes));

        // created_at harus diisi ulang lewat forceFill setelahnya.
        //
        // created_at tidak ada di $fillable, jadi nilai yang kita tulis di
        // $attributes dibuang oleh mass-assignment dan Eloquent memakai now().
        // Semua transaksi uji lalu jatuh pada jam yang sama persis, dan test
        // yang menguji panel per jam terlihat gagal padahal panelnya benar:
        // tidak ada transaksi jam 14 yang pernah benar-benar dibuat.
        $transaction->forceFill($attributes)->save();

        foreach ($lines as $line) {
            TransactionDetail::create([
                'transaction_id' => $transaction->id,
                'product_id' => $line['product_id'],
                'qty' => $line['qty'],
                'price' => $line['price'],
                'buy_price' => $line['buy_price'] ?? 0,
            ]);
        }

        return $transaction;
    }

    /** @return array<string,mixed> */
    protected function analytics(array $query = []): array
    {
        return $this->actingAs($this->admin)
            ->get(route('reports.analytics', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    // ------------------------------------------------------------------
    // Akses
    // ------------------------------------------------------------------

    public function test_page_requires_the_analytics_permission(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::firstOrCreate(['name' => 'kasir', 'guard_name' => 'web']));

        $this->actingAs($staff)
            ->get(route('reports.analytics'))
            ->assertForbidden();
    }

    public function test_super_admin_with_permission_can_open_the_page(): void
    {
        $this->analytics();

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // Konsistensi dengan FinancialReportService
    // ------------------------------------------------------------------

    public function test_kpi_matches_the_canonical_financial_summary_for_the_same_range(): void
    {
        $product = $this->product('Nasi Goreng', 25000, 12000);

        $this->transaction(
            ['grand_total' => 50000, 'taxable_amount' => 50000, 'created_at' => now()->subDays(2)],
            [['product_id' => $product->id, 'qty' => 2, 'price' => 25000, 'buy_price' => 12000]]
        );

        $this->transaction(
            ['grand_total' => 30000, 'taxable_amount' => 30000, 'created_at' => now()->subDay()],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 30000, 'buy_price' => 12000]]
        );

        $props = $this->analytics(['preset' => '7d']);
        $start = now()->subDays(6)->startOfDay();
        $end = now()->endOfDay();

        $canonical = app(FinancialReportService::class)->summary(
            Transaction::query()
                ->whereDate('created_at', '>=', $start->toDateString())
                ->whereDate('created_at', '<=', $end->toDateString()),
            Expense::query()
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
        );

        $this->assertSame((float) $canonical['net_revenue'], (float) $props['kpis']['net_revenue'],
            'Omzet bersih halaman analitik harus sama persis dengan FinancialReportService.');
        $this->assertSame((float) $canonical['hpp'], (float) $props['kpis']['hpp'],
            'HPP halaman analitik harus sama persis dengan FinancialReportService.');
        $this->assertSame((float) $canonical['net_profit'], (float) $props['kpis']['net_profit'],
            'Laba bersih halaman analitik harus sama persis dengan FinancialReportService.');
        $this->assertSame((int) $canonical['transaction_count'], (int) $props['kpis']['transaction_count']);
    }

    public function test_trend_total_equals_the_period_kpi(): void
    {
        $product = $this->product('Es Teh', 8000, 2000);

        foreach ([0, 1, 3] as $daysAgo) {
            $this->transaction(
                ['grand_total' => 8000, 'taxable_amount' => 8000, 'created_at' => now()->subDays($daysAgo)],
                [['product_id' => $product->id, 'qty' => 1, 'price' => 8000, 'buy_price' => 2000]]
            );
        }

        $props = $this->analytics(['preset' => '7d']);

        $sumRevenue = array_sum(array_column($props['trend'], 'net_revenue'));
        $sumHpp = array_sum(array_column($props['trend'], 'hpp'));
        $sumOrders = array_sum(array_column($props['trend'], 'orders'));

        $this->assertEqualsWithDelta((float) $props['kpis']['net_revenue'], $sumRevenue, 0.01,
            'Total tren omzet harus sama dengan KPI periode. Kalau tidak, ada transaksi yang hilang di salah satu jalur.');
        $this->assertEqualsWithDelta((float) $props['kpis']['hpp'], $sumHpp, 0.01,
            'Total tren HPP harus sama dengan KPI periode.');
        $this->assertSame((int) $props['kpis']['transaction_count'], (int) $sumOrders);
    }

    // ------------------------------------------------------------------
    // Lingkup transaksi
    // ------------------------------------------------------------------

    public function test_unpaid_and_refunded_transactions_are_excluded(): void
    {
        $product = $this->product('Kopi', 18000, 7000);

        $this->transaction(
            ['grand_total' => 18000, 'taxable_amount' => 18000],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 18000, 'buy_price' => 7000]]
        );

        // QRIS yang belum dibayar.
        $this->transaction(
            [
                'grand_total' => 99000,
                'taxable_amount' => 99000,
                'payment_method' => 'qris_manual',
                'payment_status' => 'pending',
                'created_at' => now(),
            ],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 99000, 'buy_price' => 7000]]
        );

        // Transaksi yang sudah direfund.
        $this->transaction(
            ['grand_total' => 55000, 'taxable_amount' => 55000, 'status' => 'refunded'],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 55000, 'buy_price' => 7000]]
        );

        $props = $this->analytics(['preset' => 'today']);

        $this->assertSame(1, (int) $props['kpis']['transaction_count'],
            'Hanya transaksi lunas yang boleh dihitung.');
        $this->assertEqualsWithDelta(18000, (float) $props['kpis']['net_revenue'], 0.01,
            'Omzet dari transaksi pending dan refunded tidak boleh ikut.');
    }

    // ------------------------------------------------------------------
    // Sumbu waktu
    // ------------------------------------------------------------------

    public function test_trend_keeps_days_without_sales_instead_of_skipping_them(): void
    {
        $product = $this->product('Roti', 10000, 4000);

        $this->transaction(
            ['grand_total' => 10000, 'taxable_amount' => 10000, 'created_at' => now()],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 10000, 'buy_price' => 4000]]
        );

        $props = $this->analytics(['preset' => '7d']);

        $this->assertCount(7, $props['trend'],
            'Periode 7 hari harus menghasilkan 7 titik, bukan hanya hari yang ada penjualan.');

        $emptyDays = array_filter($props['trend'], fn (array $row) => $row['orders'] === 0);

        $this->assertNotEmpty($emptyDays, 'Harus ada hari tanpa penjualan yang tetap diplot sebagai nol.');
        $this->assertSame(0, (int) $props['trend'][0]['net_revenue'],
            'Bucket pertama untuk hari tanpa penjualan harus nol, bukan kosong.');
    }

    public function test_weekly_granularity_buckets_start_on_monday(): void
    {
        $props = $this->analytics(['preset' => 'this_year', 'granularity' => 'weekly']);

        foreach ($props['trend'] as $row) {
            $date = CarbonImmutable::parse($row['key']);

            $this->assertSame(1, $date->dayOfWeekIso,
                "Bucket {$row['key']} harus jatuh di hari Senin, bukan ".substr($date->format('l'), 0, 3).'.');
        }
    }

    public function test_granularity_falls_back_to_daily_weekly_and_monthly_by_range_length(): void
    {
        $this->assertSame('daily', $this->analytics(['preset' => '7d'])['period']['granularity']);
        $this->assertSame('daily', $this->analytics(['preset' => '30d'])['period']['granularity']);
        $this->assertSame('weekly', $this->analytics(['preset' => 'this_year'])['period']['granularity']);
    }

    public function test_unknown_granularity_falls_back_to_auto(): void
    {
        $props = $this->analytics(['preset' => '7d', 'granularity' => 'per jam']);

        $this->assertContains($props['period']['granularity'], ['daily', 'weekly', 'monthly'],
            'Granularitas ngawur harus jatuh ke pilihan yang valid, bukan dipakai apa adanya.');
    }

    // ------------------------------------------------------------------
    // Perbandingan periode
    // ------------------------------------------------------------------

    public function test_delta_compares_against_the_previous_period_of_the_same_length(): void
    {
        $product = $this->product('Sate', 20000, 9000);

        // Periode sekarang: 2 transaksi.
        $this->transaction(
            ['grand_total' => 40000, 'taxable_amount' => 40000, 'created_at' => now()->subDay()],
            [['product_id' => $product->id, 'qty' => 2, 'price' => 20000, 'buy_price' => 9000]]
        );

        // Periode sebelumnya: 1 transaksi dengan omzet setengahnya.
        $this->transaction(
            ['grand_total' => 20000, 'taxable_amount' => 20000, 'created_at' => now()->subDays(8)],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 20000, 'buy_price' => 9000]]
        );

        $props = $this->analytics(['preset' => '7d']);

        $this->assertSame(7, (int) $props['period']['days']);

        // Periode utama untuk preset 7d adalah hari ini mundur 6 hari.
        // Pembanding harus langsung sebelum itu, dengan panjang yang sama.
        $this->assertSame(
            now()->subDays(6)->subDays(7)->toDateString(),
            $props['comparison']['start'],
            'Pembanding harus mulai tepat 7 hari sebelum periode utama, bukan 7 hari sebelum hari ini.'
        );
        $this->assertSame(
            now()->subDays(7)->toDateString(),
            $props['comparison']['end'],
            'Pembanding harus berakhir sehari sebelum periode utama dimulai.'
        );
        $this->assertSame(100.0, (float) $props['deltas']['net_revenue'],
            'Omzet naik dari 20.000 ke 40.000 sama dengan naik 100%.');
    }

    public function test_delta_is_null_when_the_previous_period_has_nothing_to_compare(): void
    {
        $product = $this->product('Kentang Goreng', 12000, 6000);

        $this->transaction(
            ['grand_total' => 12000, 'taxable_amount' => 12000, 'created_at' => now()],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 12000, 'buy_price' => 6000]]
        );

        $props = $this->analytics(['preset' => '7d']);

        $this->assertNull($props['deltas']['net_revenue'],
            'Periode lalu nol tidak punya pembanding. Menampilkan 0% akan terbaca sebagai "tidak berubah".');

        $this->assertNull($props['deltas']['margin_percent'],
            'Persentase margin selalu terisi 0% walau periode pembanding kosong, karena dihitung '
            .'dari omzet nol. Itu bukan margin sungguhan, jadi delta-nya tidak boleh dihitung.');
    }

    public function test_margin_delta_is_reported_when_the_previous_period_had_sales(): void
    {
        $product = $this->product('Soto', 20000, 8000);

        // Periode sekarang: margin 60%.
        $this->transaction(
            ['grand_total' => 20000, 'taxable_amount' => 20000, 'created_at' => now()->subDay()],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 20000, 'buy_price' => 8000]]
        );

        // Periode sebelumnya: margin 40% (HPP 12.000 dari 20.000).
        $this->transaction(
            ['grand_total' => 20000, 'taxable_amount' => 20000, 'created_at' => now()->subDays(8)],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 20000, 'buy_price' => 12000]]
        );

        $props = $this->analytics(['preset' => '7d']);

        $this->assertEqualsWithDelta(60.0, (float) $props['kpis']['margin_percent'], 0.1);
        $this->assertEqualsWithDelta(20.0, (float) $props['deltas']['margin_percent'], 0.1,
            'Margin naik dari 40% ke 60% adalah naik 20 poin persen.');
    }

    // ------------------------------------------------------------------
    // Panel
    // ------------------------------------------------------------------

    public function test_top_products_uses_snapshot_price_not_current_master_price(): void
    {
        $product = $this->product('Ayam Bakar', 30000, 15000);

        $this->transaction(
            ['grand_total' => 30000, 'taxable_amount' => 30000],
            [['product_id' => $product->id, 'qty' => 2, 'price' => 30000, 'buy_price' => 15000]]
        );

        // Harga master naik setelah penjualan. Omzet historis tidak boleh ikut naik.
        $product->update(['sell_price' => 45000, 'buy_price' => 20000]);

        $props = $this->analytics(['preset' => 'today']);
        $top = $props['topProducts'][0];

        $this->assertSame('Ayam Bakar', $top['title']);
        $this->assertEqualsWithDelta(60000, (float) $top['revenue'], 0.01,
            'Omzet harus dari harga saat transaksi, bukan harga master sekarang.');
        $this->assertEqualsWithDelta(30000, (float) $top['hpp'], 0.01,
            'HPP harus dari snapshot buy_price, bukan buy_price master sekarang.');
    }

    public function test_product_margin_panel_excludes_products_with_no_revenue_in_the_period(): void
    {
        $product = $this->product('Produk Digoreng', 10000, 5000);

        $props = $this->analytics(['preset' => 'today']);

        $this->assertSame([], $props['productMargins'],
            'Produk tanpa omzet di periode ini tidak punya margin untuk dinilai.');
    }

    public function test_product_margin_panel_orders_by_profit_not_by_percentage(): void
    {
        // Dua produk dengan karakter yang sama persis, supaya urutannya
        // benar-benar ditentukan oleh aturan yang diuji:
        //   - Produk A: omzet 10.000, HPP 9.500 -> laba 500, margin 5%
        //   - Produk B: omzet 20.000, HPP 8.000 -> laba 12.000, margin 60%
        //
        // Kalau diurutkan berdasarkan persentase, A (5%) akan muncul di bawah
        // B (60%) dan panelnya terlihat benar. Padahal A menyumbang laba
        // jauh lebih kecil, dan itulah yang perlu diketahui pemilik.
        $cheap = $this->product('Murah Tapi Mahal', 10000, 9500);
        $pricey = $this->product('Mahal Tapi Menghasilkan Labanya', 20000, 8000);


        $this->transaction(
            ['grand_total' => 10000, 'taxable_amount' => 10000],
            [['product_id' => $cheap->id, 'qty' => 1, 'price' => 10000, 'buy_price' => 9500]]
        );

        $this->transaction(
            ['grand_total' => 20000, 'taxable_amount' => 20000],
            [['product_id' => $pricey->id, 'qty' => 1, 'price' => 20000, 'buy_price' => 8000]]
        );

        $props = $this->analytics(['preset' => 'today']);
        $titles = array_column($props['productMargins'], 'title');

        $this->assertSame(
            'Mahal Tapi Menghasilkan Labanya',
            $titles[0],
            'Urutan harus dari laba nominal terbesar, bukan dari persentase margin. '
            .'Margin 5% dari omzet Rp 10.000 lebih kecil secara absolut '
            .'daripada margin 60% dari omzet Rp 20.000.'
        );
    }

    public function test_hour_panel_always_returns_all_24_hours(): void
    {
        $product = $this->product('Kopi Susu', 19000, 8000);

        $this->transaction(
            [
                'grand_total' => 19000,
                'taxable_amount' => 19000,
                'created_at' => now()->setTime(14, 30),
            ],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 19000, 'buy_price' => 8000]]
        );

        $props = $this->analytics(['preset' => 'today']);

        $this->assertCount(24, $props['byHour'], 'Panel jam harus selalu punya 24 titik.');

        $busy = collect($props['byHour'])->firstWhere('hour', 14);

        $this->assertSame(1, (int) $busy['orders'], 'Transaksi jam 14 harus terhitung di jam 14.');
        $this->assertSame(0, (int) collect($props['byHour'])->firstWhere('hour', 3)['orders']);
    }

    public function test_weekday_panel_starts_on_monday(): void
    {
        $props = $this->analytics(['preset' => 'this_month']);

        $this->assertSame(
            ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'],
            array_column($props['byWeekday'], 'name'),
            'Urutan hari dimulai Senin karena itu urutan yang dipakai jadwal shift.'
        );
    }

    public function test_weekday_panel_counts_calendar_days_not_iso_weekdays(): void
    {
        $product = $this->product('Kentang Goreng', 10000, 4000);

        // 7 hari ke belakang, ditambah hari ini, selalu memuat tepat satu kali
        // setiap hari dalam seminggu.
        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $this->transaction(
                ['grand_total' => 10000, 'taxable_amount' => 10000, 'created_at' => now()->subDays($daysAgo)],
                [['product_id' => $product->id, 'qty' => 1, 'price' => 10000, 'buy_price' => 4000]]
            );
        }

        $props = $this->analytics(['preset' => '7d']);
        $days = array_column($props['byWeekday'], 'days', 'name');

        foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'] as $day) {
            $this->assertSame(1, (int) $days[$day],
                "Dalam rentang 7 hari, '$day' harus dihitung tepat 1 kali. "
                .'Kalau tidak, penghitung hari memakai penomoran ISO (1=Senin s/d 7=Minggu) '
                .'lalu dipetakan ke penomoran MySQL (1=Minggu s/d 7=Sabtu), dankey 1 '
                .'akan berisi 7 sementara key 7 berisi 0.');
        }

        $this->assertSame(7, array_sum($days),
            'Jumlah hari dari semua panel harus sama dengan panjang periode.');
    }

    public function test_weekday_average_per_day_uses_the_real_number_of_that_weekday(): void
    {
        $product = $this->product('Kopek', 20000, 9000);

        $start = now()->subDays(6)->startOfDay();

        // Satu transaksi pada tanggal mulai, memakai jam 12:00.
        //
        // Rentangnya 7 hari supaya setiap hari dalam seminggu muncul tepat
        // sekali. Dengan rentang 14 hari, hari yang sama muncul dua kali dan
        // rata-ratanya jadi 0,5 — angka yang benar secara hitungan tapi
        // menyesatkan untuk test yang tujuannya memeriksa pemetaan
        // hari, bukan ratanya.
        $this->transaction(
            [
                'grand_total' => 20000,
                'taxable_amount' => 20000,
                'created_at' => $start->setTime(12, 0, 0),
            ],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 20000, 'buy_price' => 9000]]
        );

        $props = $this->analytics([
            'preset' => 'custom',
            'start_date' => $start->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $expected = $start->format('l');
        $expectedName = [
            'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu',
        ][$expected];

        $row = collect($props['byWeekday'])->firstWhere('name', $expectedName);

        $this->assertSame(1, (int) $row['orders'],
            "Transaksi tanggal {$start->toDateString()} ({$expectedName}) harus masuk ke baris {$expectedName}.");
        $this->assertEqualsWithDelta(1.0, (float) $row['orders_per_day'], 0.1,
            "Rata-rata per hari untuk {$expectedName} harus 1, karena tanggal itu ada di rentang ini.");
        // Panel ini menampilkan OMZET, bukan laba. Transaksinya Rp 20.000, jadi
        // rata-rata per hari untuk hari itu juga Rp 20.000. HPP Rp 9.000
        // hanya muncul di panel HPP, tidak dikurangi di sini.
        $this->assertEqualsWithDelta(20000, (float) $row['revenue_per_day'], 1.0,
            "Omzet rata-rata per hari untuk {$expectedName} harus sama dengan omzet satu hari itu.");
    }

    public function test_cashier_panel_attributes_hpp_per_cashier_instead_of_splitting_evenly(): void
    {
        $product = $this->product('Nasi Ujang', 15000, 5000);

        $other = User::factory()->create(['name' => 'Kasir Kedua']);

        // Kasir utama: 1 transaksi, HPP 5.000.
        $this->transaction(
            ['grand_total' => 15000, 'taxable_amount' => 15000, 'cashier_id' => $this->admin->id],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 15000, 'buy_price' => 5000]]
        );

        // Kasir kedua: 9 transaksi, HPP 45.000. HPP-nya harus nyambung ke dia.
        for ($i = 0; $i < 9; $i++) {
            $this->transaction(
                ['grand_total' => 15000, 'taxable_amount' => 15000, 'cashier_id' => $other->id],
                [['product_id' => $product->id, 'qty' => 1, 'price' => 15000, 'buy_price' => 5000]]
            );
        }

        $props = $this->analytics(['preset' => 'today']);

        $byName = collect($props['byCashier'])->keyBy('name');

        $this->assertEqualsWithDelta(5000, (float) $byName['Kasir Uji']['hpp'], 0.01,
            'HPP harus mengikuti transaksi kasir itu sendiri.');
        $this->assertEqualsWithDelta(45000, (float) $byName['Kasir Kedua']['hpp'], 0.01,
            'HPP harus mengikuti transaksi kasir itu sendiri, bukan dibagi rata.');

        $this->assertEqualsWithDelta(50000, (float) $byName['Kasir Uji']['hpp'] + (float) $byName['Kasir Kedua']['hpp'], 0.01,
            'Total HPP per kasir harus sama dengan total HPP periode.');
    }

    public function test_category_panel_groups_by_category(): void
    {
        $makanan = $this->category;
        $minuman = Category::create(['name' => 'Minuman']);

        $nasi = $this->product('Nasi', 15000, 7000);
        $nasi->update(['category_id' => $makanan->id]);

        $teh = $this->product('Teh Manis', 6000, 1500);
        $teh->update(['category_id' => $minuman->id]);

        $this->transaction(
            ['grand_total' => 15000, 'taxable_amount' => 15000],
            [['product_id' => $nasi->id, 'qty' => 1, 'price' => 15000, 'buy_price' => 7000]]
        );

        $this->transaction(
            ['grand_total' => 6000, 'taxable_amount' => 6000],
            [['product_id' => $teh->id, 'qty' => 1, 'price' => 6000, 'buy_price' => 1500]]
        );

        $props = $this->analytics(['preset' => 'today']);

        $names = array_column($props['byCategory'], 'name');

        $this->assertContains('Makanan', $names);
        $this->assertContains('Minuman', $names);
    }

    public function test_payment_method_panel_reports_the_raw_method(): void
    {
        $product = $this->product('Kentang', 10000, 4000);

        $this->transaction(
            ['grand_total' => 10000, 'taxable_amount' => 10000, 'payment_method' => 'qris_manual'],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 10000, 'buy_price' => 4000]]
        );

        $props = $this->analytics(['preset' => 'today']);

        $this->assertSame('qris_manual', $props['byPaymentMethod'][0]['method'],
            'Frontend yang menerjemahkan label, jadi backend harus kirim nilai aslinya.');
    }

    // ------------------------------------------------------------------
    // Analitik promosi
    // ------------------------------------------------------------------

    public function test_promo_panel_splits_discount_into_automatic_and_voucher(): void
    {
        $product = $this->product('Nasi Ujang', 100000, 40000);

        // Diskon otomatis Rp 10.000 (10% dari Rp 100.000).
        $this->transaction(
            [
                'grand_total' => 90000,
                'taxable_amount' => 90000,
                'discount' => 10000,
                'voucher_discount' => 0,
            ],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 100000, 'buy_price' => 40000]]
        );

        // Diskon otomatis Rp 10.000 + voucher Rp 8.000.
        $this->transaction(
            [
                'grand_total' => 82000,
                'taxable_amount' => 82000,
                'discount' => 18000,
                'voucher_discount' => 8000,
                'voucher_code' => 'HEMAT10',
            ],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 100000, 'buy_price' => 40000]]
        );

        $promo = $this->analytics(['preset' => 'today'])['promo'];

        $this->assertEqualsWithDelta(28000, (float) $promo['total_discount'], 0.01);
        $this->assertEqualsWithDelta(20000, (float) $promo['global_discount'], 0.01,
            'Diskon otomatis = total diskon dikurangi bagian voucher.');
        $this->assertEqualsWithDelta(8000, (float) $promo['voucher_discount'], 0.01);
    }

    public function test_promo_discount_percent_uses_revenue_before_discount(): void
    {
        $product = $this->product('Rendang', 100000, 50000);

        // taxable_amount 90.000 sudah NETO dari diskon 10.000.
        //
        // Kalau persen dihitung dari taxable_amount, diskon terlihat 11,1%
        // (10.000 / 90.000). Padahal pelanggan benar-benar membayar 100.000
        // dan dapat potongan 10%, jadi angkanya harus 10%.
        $this->transaction(
            [
                'grand_total' => 90000,
                'taxable_amount' => 90000,
                'discount' => 10000,
            ],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 100000, 'buy_price' => 50000]]
        );

        $promo = $this->analytics(['preset' => 'today'])['promo'];

        $this->assertEqualsWithDelta(100000, (float) $promo['revenue_before_discount'], 0.01,
            'Omzet sebelum diskon = omzet bersih + diskon, bukan omzet bersih saja.');
        $this->assertEqualsWithDelta(10.0, (float) $promo['discount_percent_of_revenue'], 0.05);
    }

    public function test_promo_groups_partition_every_transaction(): void
    {
        $product = $this->product('Sate', 50000, 20000);

        $this->transaction(
            ['grand_total' => 45000, 'taxable_amount' => 45000, 'discount' => 5000],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 50000, 'buy_price' => 20000]]
        );

        $this->transaction(
            ['grand_total' => 50000, 'taxable_amount' => 50000, 'discount' => 0],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 50000, 'buy_price' => 20000]]
        );

        $this->transaction(
            ['grand_total' => 50000, 'taxable_amount' => 50000, 'discount' => 0],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 50000, 'buy_price' => 20000]]
        );

        $promo = $this->analytics(['preset' => 'today'])['promo'];

        $this->assertSame(1, (int) $promo['with_promo']['orders']);
        $this->assertSame(2, (int) $promo['without_promo']['orders'],
            'Transaksi tanpa diskon harus masuk kelompoknya sendiri. Kalau nol, '
            .'kondisi query-nya menumpuk dan kelompok pertama menelan sisanya.');
        $this->assertSame(3, (int) $promo['total_orders']);
        $this->assertSame(
            (int) $promo['total_orders'],
            (int) $promo['with_promo']['orders'] + (int) $promo['without_promo']['orders'],
            'Kedua kelompok harus mencakup semua transaksi, tanpa ada yang hilang.'
        );
    }

    public function test_promo_voucher_breakdown_groups_by_code(): void
    {
        $product = $this->product('Kopi', 20000, 8000);

        foreach (['HEMAT10', 'HEMAT10', 'GAJI20'] as $index => $code) {
            $this->transaction(
                [
                    'grand_total' => 18000,
                    'taxable_amount' => 18000,
                    'discount' => 2000,
                    'voucher_discount' => 2000,
                    'voucher_code' => $code,
                ],
                [['product_id' => $product->id, 'qty' => 1, 'price' => 20000, 'buy_price' => 8000]]
            );
        }

        // Tanpa kode voucher: tidak boleh muncul di tabel voucher.
        $this->transaction(
            ['grand_total' => 19000, 'taxable_amount' => 19000, 'discount' => 1000],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 20000, 'buy_price' => 8000]]
        );

        $promo = $this->analytics(['preset' => 'today'])['promo'];
        $codes = array_column($promo['vouchers'], 'code');

        sort($codes);
        $this->assertSame(['GAJI20', 'HEMAT10'], $codes);

        $hemat = collect($promo['vouchers'])->firstWhere('code', 'HEMAT10');
        $this->assertSame(2, (int) $hemat['orders']);
        $this->assertEqualsWithDelta(4000, (float) $hemat['discount'], 0.01);

        // Sisa diskon otomatis harus terpisah dari voucher.
        $this->assertEqualsWithDelta(1000, (float) $promo['global_discount'], 0.01,
            'Diskon otomatis hanya dari transaksi tanpa kode voucher.');
    }

    public function test_promo_hpp_uses_the_batch_snapshot(): void
    {
        $product = $this->product('Ayam Bakar', 100000, 45000);

        $this->transaction(
            [
                'grand_total' => 90000,
                'taxable_amount' => 90000,
                'discount' => 10000,
            ],
            [['product_id' => $product->id, 'qty' => 2, 'price' => 100000, 'buy_price' => 45000]]
        );

        $promo = $this->analytics(['preset' => 'today'])['promo'];

        $this->assertEqualsWithDelta(90000, (float) $promo['with_promo']['hpp'], 0.01,
            'HPP kelompok harus 2 x 45.000 dari snapshot batch.');
        $this->assertEqualsWithDelta(0, (float) $promo['without_promo']['hpp'], 0.01,
            'Kelompok tanpa promo tidak boleh ikut menanggung HPP.');
        $this->assertEqualsWithDelta(0, (float) $promo['without_promo']['gross_profit'], 0.01);
    }

    public function test_promo_ignores_unpaid_and_refunded_transactions(): void
    {
        $product = $this->product('Iga', 100000, 60000);

        $this->transaction(
            ['grand_total' => 90000, 'taxable_amount' => 90000, 'discount' => 10000],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 100000, 'buy_price' => 60000]]
        );

        $this->transaction(
            [
                'grand_total' => 50000,
                'taxable_amount' => 50000,
                'discount' => 50000,
                'payment_status' => 'pending',
            ],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 100000, 'buy_price' => 60000]]
        );

        $this->transaction(
            [
                'grand_total' => 40000,
                'taxable_amount' => 40000,
                'discount' => 60000,
                'status' => 'refunded',
            ],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 100000, 'buy_price' => 60000]]
        );

        $promo = $this->analytics(['preset' => 'today'])['promo'];

        $this->assertEqualsWithDelta(10000, (float) $promo['total_discount'], 0.01,
            'Diskon dari transaksi pending dan refunded tidak boleh dihitung.');
        $this->assertSame(1, (int) $promo['total_orders']);
    }

    // ------------------------------------------------------------------
    // Beban operasional
    // ------------------------------------------------------------------

    public function test_stock_purchase_and_capital_withdrawal_do_not_reduce_profit(): void
    {
        $product = $this->product('Kopek', 20000, 9000);

        $this->transaction(
            ['grand_total' => 20000, 'taxable_amount' => 20000],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 20000, 'buy_price' => 9000]]
        );

        // Belanja stok: kas jadi persediaan, bukan beban.
        Expense::create([
            'user_id' => $this->admin->id,
            'name' => 'Pembelian bahan',
            'category' => 'Operasional',
            'amount' => 500000,
            'source' => Expense::SOURCE_STOCK_PURCHASE,
            'date' => now()->toDateString(),
        ]);

        // Modal keluar: penarikan modal, bukan biaya.
        Expense::create([
            'user_id' => $this->admin->id,
            'name' => 'Tarik modal',
            'category' => 'Operasional',
            'amount' => 300000,
            'source' => Expense::SOURCE_CAPITAL,
            'date' => now()->toDateString(),
        ]);

        // Beban operasional yang benar.
        Expense::create([
            'user_id' => $this->admin->id,
            'name' => 'Listrik',
            'category' => 'Operasional',
            'amount' => 45000,
            'source' => Expense::SOURCE_CASH,
            'date' => now()->toDateString(),
        ]);

        $props = $this->analytics(['preset' => 'today']);

        $this->assertEqualsWithDelta(45000, (float) $props['kpis']['operating_expenses'], 0.01,
            'Belanja stok dan modal keluar tidak boleh memotong laba.');
        $this->assertEqualsWithDelta(20000 - 9000 - 45000, (float) $props['kpis']['net_profit'], 0.01);
    }

    public function test_debt_repayment_is_not_counted_as_operating_expense(): void
    {
        $product = $this->product('Kue', 12000, 5000);

        $this->transaction(
            ['grand_total' => 12000, 'taxable_amount' => 12000],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 12000, 'buy_price' => 5000]]
        );

        Expense::create([
            'user_id' => $this->admin->id,
            'name' => 'Pelunasan Hutang',
            'category' => 'Pelunasan Hutang',
            'amount' => 250000,
            'date' => now()->toDateString(),
        ]);

        $props = $this->analytics(['preset' => 'today']);

        $this->assertEqualsWithDelta(0, (float) $props['kpis']['operating_expenses'], 0.01,
            'Pelunasan hutang adalah arus kas, bukan beban.');
    }

    // ------------------------------------------------------------------
    // Pengaman periode
    // ------------------------------------------------------------------

    public function test_reversed_custom_range_is_swapped_instead_of_reading_all_data(): void
    {
        $product = $this->product('Kentang Goreng', 10000, 4000);

        $this->transaction(
            ['grand_total' => 10000, 'taxable_amount' => 10000, 'created_at' => now()],
            [['product_id' => $product->id, 'qty' => 1, 'price' => 10000, 'buy_price' => 4000]]
        );

        // Tanggal terbalik: akhir lebih dulu.
        $props = $this->analytics([
            'preset' => 'custom',
            'start_date' => now()->toDateString(),
            'end_date' => now()->subDays(3)->toDateString(),
        ]);

        $this->assertSame(
            now()->subDays(3)->toDateString(),
            $props['period']['start'],
            'Rentang terbalik harus ditukar, bukan dibaca apa adanya.'
        );
        $this->assertSame(now()->toDateString(), $props['period']['end']);
        $this->assertSame(1, (int) $props['kpis']['transaction_count']);
    }

    public function test_unknown_preset_falls_back_instead_of_returning_an_empty_period(): void
    {
        $props = $this->analytics(['preset' => 'ngawur']);

        $this->assertSame('30d', $props['period']['preset']);
        $this->assertSame(30, (int) $props['period']['days']);
    }

    public function test_page_reports_empty_state_when_there_is_no_sale(): void
    {
        $props = $this->analytics(['preset' => 'today']);

        $this->assertTrue($props['empty'],
            'Halaman harus menandai dirinya kosong, supaya frontend bisa menjelaskan '
            .'kenapa tidak ada angka, bukan menampilkan panel kosong tanpa keterangan.');
        $this->assertSame([], $props['topProducts']);
        $this->assertSame([], $props['byCashier']);
        $this->assertCount(24, $props['byHour']);
    }

    public function test_low_stock_panel_only_reports_ingredients_below_their_minimum(): void
    {
        Ingredient::create([
            'name' => 'Beras',
            'buy_price' => 15000,
            'stock' => 5,
            'min_stock' => 10,
            'unit' => 'Kg',
        ]);

        Ingredient::create([
            'name' => 'Gula',
            'buy_price' => 16000,
            'stock' => 50,
            'min_stock' => 10,
            'unit' => 'Kg',
        ]);

        $props = $this->analytics(['preset' => 'today']);

        $this->assertCount(1, $props['lowStock']);
        $this->assertSame('Beras', $props['lowStock'][0]['name']);
        $this->assertEqualsWithDelta(50, (float) $props['lowStock'][0]['ratio'], 0.1,
            '5 dari minimum 10 berarti 50% dari batas minimum.');
        $this->assertEqualsWithDelta(5, (float) $props['lowStock'][0]['shortfall'], 0.01);
    }
}
