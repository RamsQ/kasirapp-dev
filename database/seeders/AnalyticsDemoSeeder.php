<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\CogsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Data dummy khusus untuk menguji halaman Analitik Grafis.
 *
 * Berbeda dengan UatDemoSeeder yang sengaja membuat 10 transaksi supaya
 * angkanya bisa diperiksa satu per satu, seeder ini membuat VOLUME yang
 * tersebar rapi supaya setiap panel punya sesuatu untuk ditampilkan:
 *
 *  - 90 hari ke belakang, sehingga tombol "Bulan Lalu" dan "Tahun Ini" punya
 *    isi, dan granularitas otomatis benar-benar berubah (harian -> mingguan).
 *  - Transaksi hanya di jam sibuk (11-21). Panel "Jam Ramai" jadi punya
 *    pola yang terlihat, dan terlihat bedanya dengan panel yang datanya acak.
 *  - Akhir pekan lebih ramai dari hari kerja. Panel "Pola per Hari" kalau
 *    hasilnya justru turun di hari yang paling sepi, berarti grafiknya salah
 *    baca, bukan karena elemennya rusak.
 *  - Beberapa hari sengaja DIKOSONGKAN, termasuk akhir pekan. Ini menguji
 *    sumbu waktu: titik kosong harus tetap muncul dengan angka nol.
 *  - Beberapa produk dengan margin jauh berbeda, dari yang sampai 5% sampai
 *    yang 70% ke atas, supaya panel "Margin Tertinggi" bisa diurutkan dengan
 *    benar dan urutannya bisa dibandingkan dengan "Produk Terlaris".
 *  - Semua metode pembayaran, lebih dari satu kasir, dan bahan baku yang
 *    melewati batas minimum.
 *
 * HPP TIDAK dikarang. Baris transaksi ditulis dengan buy_price hasil alokasi
 * batch sungguhan dari CogsService, sama seperti alur kasir. Kalau diisi
 * manual, panel HPP akan terlihat benar padahal tidak sedang menguji apa pun.
 *
 * NON-DESTRUKTIVE: tidak menghapus apa pun, tidak menyentuh settings,
 * payment_settings, atau user. Aman dijalankan berkali-kali karena invoice
 * prefixed ANALYTICS- dan akan dilewati kalau sudah ada.
 *
 * Bersihkan dengan: php artisan reset:operational --force
 */
class AnalyticsDemoSeeder extends Seeder
{
    private const INVOICE_PREFIX = 'ANALYTICS-';

    private const DAYS_BACK = 90;

    /** Aturan diskon global, dicatat supaya panel promosi punya sumber nyata. */
    private const PROMO_MIN_TRANSACTION = 50_000;

    private const PROMO_PERCENTAGE = 10;

    private const VOUCHER_PERCENT = 20;

    /** Kode voucher yang dibuat juga di tabel promo_codes. */
    private const VOUCHER_CODES = ['HEMAT10', 'GAJI20', 'SABTU25'];

    public function run(): void
    {
        if (Transaction::where('invoice', 'like', self::INVOICE_PREFIX.'%')->exists()) {
            $this->command->warn('Data dummy Analitik sudah ada. Seeder dilewati.');
            $this->command->info('Untuk membuat ulang: php artisan reset:operational --force');

            return;
        }

        $this->command->info('Menyiapkan data dummy untuk halaman Analitik...');

        $cashiers = $this->ensureCashiers();
        $categories = $this->ensureCategories();
        $ingredients = $this->ensureLowStockIngredients();
        $products = $this->ensureProducts($categories);
        $customers = $this->ensureCustomers();

        $made = $this->seedTransactions($cashiers, $products, $customers);
        $promoRows = $this->seedPromoRules();
        $kitchenOrders = $this->syncKitchenStatus();

        $this->command->info(sprintf(
            'Selesai. %d transaksi dibuat untuk %d produk, %d kategori, %d kasir, %d pelanggan. '
            .'Promo: %d aturan diskon, %d kode voucher. KDS: %d order ditandai sebagai menunggu dapur.',
            $made,
            count($products),
            count($categories),
            count($cashiers),
            count($customers),
            $promoRows['discounts'],
            $promoRows['promo_codes'],
            $kitchenOrders
        ));
        $this->command->info('Buka /dashboard/analytics dan coba semua preset periode.');
    }

    /**
     * Buat aturan diskon dan kode voucher yang dipakai transaksi demo.
     *
     * Aturan diskonnya dicatat di tabel `discounts` dan kodenya di
     * `promo_codes`, supaya tab Promosi tidak menampilkan nomor yang tidak
     * bisa dicari asal-usulnya di UI.
     *
     * Catatan jujur: `transactions` tidak menyimpan `discount_id`, jadi
     * transaksi demo yang dapat diskon global TIDAK bisa ditelusuri ke baris
     * mana pun di `discounts`. Yang bisa ditelusuri hanya voucher, lewat
     * `voucher_code` yang memang disimpan per transaksi. Tab Promosi karena
     * itu tidak menampilkan peringkat per aturan diskon.
     *
     * @return array{discounts: int, promo_codes: int}
     */
    private function seedPromoRules(): array
    {
        Discount::updateOrCreate(
            ['name' => 'Diskon Global Demo 10%'],
            [
                'description' => 'Potongan 10% otomatis untuk transaksi di atas minimum.',
                'type' => 'percentage',
                'value' => self::PROMO_PERCENTAGE,
                'min_transaction' => self::PROMO_MIN_TRANSACTION,
                'start_date' => CarbonImmutable::now()->subDays(self::DAYS_BACK)->toDateString(),
                'end_date' => CarbonImmutable::now()->addDays(30)->toDateString(),
                'is_active' => true,
            ]
        );

        $codes = [
            ['HEMAT10', 'Voucher Hemat 10', 10],
            ['GAJI20', 'Voucher Gajian 20', 20],
            ['SABTU25', 'Voucher Sabtu 25', 25],
        ];

        foreach ($codes as [$code, $name, $percent]) {
            PromoCode::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => 'percentage',
                    'value' => $percent,
                    'min_spend' => self::PROMO_MIN_TRANSACTION,
                    'starts_at' => CarbonImmutable::now()->subDays(self::DAYS_BACK),
                    'expires_at' => CarbonImmutable::now()->addDays(60),
                    'max_uses' => 200,
                    'used_count' => 0,
                    'is_active' => true,
                ]
            );
        }

        return [
            'discounts' => 1,
            'promo_codes' => count($codes),
        ];
    }

    /**
     * Samakan status dapur dengan sakelar KDS.
     *
     * Transaksi di seeder ini dibuat tanpa `kitchen_status`, jadi kolomnya
     * memakai nilai default 'pending'. Akibatnya SEMUA order demo — termasuk
     * yang dibuat berminggu-minggu lalu saat KDS masih mati — terlihat sebagai
     * "menunggu dimasak", dan menyalakan KDS langsung menampilkan seluruh
     * riwayat.
     *
     * Prinsipnya sama dengan yang dilakukan TransactionController: kalau KDS
     * mati, order tidak pernah masuk dapur, jadi statusnya 'completed'.
     *
     * @return int jumlah order yang ditandai menunggu dapur
     */
    private function syncKitchenStatus(): int
    {
        $enabled = (bool) (Setting::query()->value('kitchen_display_enabled') ?? false);
        $target = $enabled ? 'pending' : 'completed';

        $transactions = DB::table('transactions')
            ->where('invoice', 'like', self::INVOICE_PREFIX.'%')
            ->update(['kitchen_status' => $target]);

        DB::table('holds')
            ->update(['kitchen_status' => $target]);

        return $enabled ? (int) $transactions : 0;
    }

    /**
     * @return array<int, User>
     */
    private function ensureCashiers(): array
    {
        $admin = User::where('email', 'admin@gmail.com')->first();

        $extra = [];
        foreach (['Kasir Demo Rina', 'Kasir Demo Bayu'] as $name) {
            $email = str($name)->slug()->append('@demo.lakupos.test')->value();

            // firstOrCreate DENGAN password. Kolom password tidak punya nilai
            // default, jadi User::firstOrCreate(['email' => ...], ['name' => ...])
            // akan gagal dengan "Field 'password' doesn't have a default value".
            $extra[] = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('demo-analitik-2026'),
                ]
            );
        }

        return array_values(array_filter([$admin, ...$extra]));
    }

    /**
     * @return array<string, Category>
     */
    private function ensureCategories(): array
    {
        $names = ['Makanan', 'Minuman', 'Camilan', 'Paket Hemat'];

        $categories = [];

        foreach ($names as $name) {
            $categories[$name] = Category::firstOrCreate(['name' => $name]);
        }

        return $categories;
    }

    /**
     * @return array<int, Ingredient>
     */
    private function ensureLowStockIngredients(): array
    {
        // min_stock sengaja kecil supaya bahan ini muncul di panel
        // "Bahan Baku Menipis" tanpa harus lewat transaksi apa pun.
        $rows = [
            ['Beras Premium', 'Kg', 20_000, 4.0, 10.0],
            ['Minyak Goreng', 'L', 35_000, 1.5, 8.0],
            ['Gula Pasir', 'Kg', 16_000, 0.0, 5.0],
            ['Kentang Beku', 'Kg', 22_000, 9.0, 10.0],
        ];

        $ingredients = [];

        foreach ($rows as [$name, $unit, $buyPrice, $stock, $minStock]) {
            $ingredients[] = Ingredient::updateOrCreate(
                ['name' => $name],
                [
                    'unit' => $unit,
                    'buy_price' => $buyPrice,
                    'stock' => $stock,
                    'min_stock' => $minStock,
                ]
            );
        }

        return $ingredients;
    }

    /**
     * @param  array<string, Category>  $categories
     * @return array<int, Product>
     */
    private function ensureProducts(array $categories): array
    {
        // buy_price sengaja tersebar lebar terhadap sell_price supaya margin
        // tiap produk berbeda jauh. Kalau semua margin-nya seragam, panel
        // "Margin Tertinggi" akan terlihat benar padahal salah urutan.
        //
        // `type` memakai 'single', nilai yang memang dipakai model Product di
        // instalasi ini. Menulis 'product' membuat MySQL memotong nilai dan
        // melempar error "Data truncated for column 'type'" karena kolomnya
        // adalah ENUM yang tidak memuat 'product'.
        $rows = [
            // barcode, judul, kategori, harga jual, harga beli
            ['ANL-P001', 'Nasi Goreng Spesial', 'Makanan', 28_000, 13_000],
            ['ANL-P002', 'Mie Goreng Ayam', 'Makanan', 24_000, 11_500],
            ['ANL-P003', 'Ayam Bakar Madu', 'Makanan', 42_000, 27_000],
            ['ANL-P004', 'Iga Bakar', 'Makanan', 45_000, 41_000],
            ['ANL-P005', 'Sate Ayam 10 Tusuk', 'Makanan', 35_000, 19_000],
            ['ANL-P006', 'Rendang Sapi', 'Makanan', 55_000, 38_000],
            ['ANL-M001', 'Es Teh Manis', 'Minuman', 8_000, 2_000],
            ['ANL-M002', 'Kopi Susu Gula Aren', 'Minuman', 22_000, 9_500],
            ['ANL-M003', 'Jus Alpukat', 'Minuman', 25_000, 14_000],
            ['ANL-M004', 'Air Mineral', 'Minuman', 6_000, 3_500],
            ['ANL-S001', 'Kentang Goreng', 'Camilan', 15_000, 7_000],
            ['ANL-S002', 'Pisang Goreng', 'Camilan', 18_000, 8_000],
            ['ANL-S003', 'Kroepoek', 'Camilan', 17_000, 4_000],
            ['ANL-S004', 'Kerupuk Udang', 'Camilan', 16_000, 2_500],
            ['ANL-T001', 'Paket Hemat 1', 'Paket Hemat', 89_000, 51_000],
            ['ANL-T002', 'Paket Hemat 2', 'Paket Hemat', 124_000, 68_000],
        ];

        $products = [];

        foreach ($rows as [$barcode, $title, $categoryName, $sellPrice, $buyPrice]) {
            $products[] = Product::updateOrCreate(
                ['barcode' => $barcode],
                [
                    'category_id' => $categories[$categoryName]->id,
                    'title' => $title,
                    'type' => 'single',
                    'sell_price' => $sellPrice,
                    'buy_price' => $buyPrice,
                    'cost_price' => $buyPrice,
                    'stock' => 500,
                ]
            );
        }

        return $products;
    }

    /**
     * @return array<int, Customer>
     */
    private function ensureCustomers(): array
    {
        $customers = [];

        // Kolom address, phone, dan email di customers TIDAK punya nilai
        // default, jadi harus diisi eksplisit. firstOrCreate(['name' => ...])
        // saja akan gagal dengan "Field 'address' doesn't have a default value".
        $index = 1;
        foreach (['Pelanggan Demo Satu', 'Pelanggan Demo Dua', 'Pelanggan Demo Tiga', 'Pelanggan Demo Empat'] as $name) {
            $customers[] = Customer::firstOrCreate(
                ['name' => $name],
                [
                    'address' => 'Alamat demo '.$index,
                    'phone' => '0812'.str_pad((string) $index, 8, '0', STR_PAD_LEFT),
                    'no_telp' => '0812'.str_pad((string) $index, 8, '0', STR_PAD_LEFT),
                ]
            );
            $index++;
        }

        return $customers;
    }

    /**
     * @param  array<int, User>  $cashiers
     * @param  array<int, Product>  $products
     * @param  array<int, Customer>  $customers
     */
    private function seedTransactions(array $cashiers, array $products, array $customers): int
    {
        $cogs = app(CogsService::class);
        $method = 'FIFO';
        $today = CarbonImmutable::now()->startOfDay();

        $made = 0;
        $sequence = 0;

        for ($daysAgo = self::DAYS_BACK; $daysAgo >= 0; $daysAgo--) {
            $date = $today->subDays($daysAgo);
            $isWeekend = $date->isSaturday() || $date->isSunday();

            // Beberapa hari sengaja dikosongkan: satu hari kerja, satu akhir
            // pekan, lalu hari yang jatuh di tengah. Ini yang membuktikan sumbu
            // waktu menaruh titik nol, bukan melompatinya.
            if (in_array($daysAgo, [11, 12, 23, 24, 45, 46, 67], true)) {
                continue;
            }

            $orders = $isWeekend
                ? random_int(14, 24)
                : random_int(6, 12);

            for ($i = 0; $i < $orders; $i++) {
                $sequence++;
                $createdAt = $this->pickHour($date);

                $made += $this->createTransaction(
                    $cashiers,
                    $products,
                    $customers,
                    $sequence,
                    $createdAt,
                    $cogs,
                    $method
                );
            }
        }

        return $made;
    }

    /**
     * Jam transaksi, dibatasi ke jam buka warung.
     *
     * Sebarannya condong ke jam makan siang dan malam, supaya panel "Jam
     * Ramai" punya pola yang benar-benar kelihatan, bukan 24 bar yang sama
     * tinggi. Acak penuh akan menghasilkan grafik yang benar secara angka
     * tapi tidak informatif untuk pengaturan shift.
     *
     * Poin penting: jam dipilih dari variabel $date, BUKAN dari now().
     *
     * Versi yang salah pernah menulis created_at dengan now()->setTime(...),
     * sehingga tanggalnya diambil ulang saat itu juga dan tidak lagi cocok
     * dengan hari yang sedang diproses. Akibatnya hampir semua transaksi
     * menumpuk di hari yang sama: 1009 transaksi tetap tercatat, tapi 83 dari
     * 90 hari pada sumbu tren kosong. Panel "Pola per Hari" ikut salah karena
     * membagi omzet ke jumlah kemunculan hari yang benar.
     */
    private function pickHour(CarbonImmutable $date): CarbonImmutable
    {
        $roll = random_int(1, 100);

        if ($roll <= 30) {
            $hour = random_int(11, 14);
        } elseif ($roll <= 70) {
            $hour = random_int(15, 19);
        } else {
            $hour = random_int(19, 21);
        }

        return $date->setTime($hour, random_int(0, 59), random_int(0, 59));
    }

    /**
     * @param  array<int, User>  $cashiers
     * @param  array<int, Product>  $products
     * @param  array<int, Customer>  $customers
     */
    private function createTransaction(
        array $cashiers,
        array $products,
        array $customers,
        int $sequence,
        $createdAt,
        CogsService $cogs,
        string $method
    ): int {
        $paymentMethods = ['cash', 'cash', 'cash', 'qris_manual', 'qris_manual', 'midtrans', 'xendit'];

        $lines = collect($products)
            ->shuffle()
            ->take(random_int(1, 4))
            ->map(fn (Product $product) => ['product' => $product, 'qty' => random_int(1, 3)])
            ->all();

        $subtotal = collect($lines)->sum(fn ($line) => $line['product']->sell_price * $line['qty']);
        $invoice = self::INVOICE_PREFIX.sprintf('%05d', $sequence);
        $paymentMethod = $paymentMethods[array_rand($paymentMethods)];

        /*
         * Diskon dan voucher, mengikuti cara kasir menghitungnya.
         *
         * Dipisah supaya panel promosi punya isi: tanpanya, 1.014 transaksi
         * semuanya tanpa diskon dan tab Promosi selalu kosong.
         *
         * Coupon kasir mengurangi total, dan taxable_amount disimpan SESUDAH
         * diskon — itu yang dilakukan TransactionController. Diskon di sini
         * sengaja tidak dikembalikan ke subtotal, supaya angkanya sama dengan
         * yang benar-benar tercatat di aplikasi.
         */
        $globalDiscount = 0;
        if ($subtotal >= self::PROMO_MIN_TRANSACTION) {
            $globalDiscount = (int) round($subtotal * (self::PROMO_PERCENTAGE / 100), 0, PHP_ROUND_HALF_UP);
        }

        $voucherCode = null;
        $voucherDiscount = 0;
        if (random_int(1, 100) <= 22) {
            $voucherCode = self::VOUCHER_CODES[array_rand(self::VOUCHER_CODES)];
            $voucherDiscount = (int) round(min(
                max(0, $subtotal - $globalDiscount),
                (int) round(($subtotal - $globalDiscount) * (self::VOUCHER_PERCENT / 100), 0, PHP_ROUND_HALF_UP)
            ), 0, PHP_ROUND_HALF_UP);
        }

        $totalDiscount = $globalDiscount + $voucherDiscount;
        $taxable = max(0, $subtotal - $totalDiscount);

        return DB::transaction(function () use (
            $cashiers, $lines, $subtotal, $invoice, $paymentMethod,
            $createdAt, $cogs, $method, $customers,
            $totalDiscount, $voucherCode, $voucherDiscount, $taxable
        ) {
            $cashier = $cashiers[array_rand($cashiers)];
            $customer = random_int(1, 100) <= 45
                ? $customers[array_rand($customers)]
                : null;

            $transaction = Transaction::create([
                'cashier_id' => $cashier->id,
                'customer_id' => $customer?->id,
                'invoice' => $invoice,
                'cash' => $subtotal,
                'change' => 0,
                'discount' => $totalDiscount,
                'voucher_code' => $voucherCode,
                'voucher_discount' => $voucherDiscount,
                'taxable_amount' => $taxable,
                'tax_rate' => null,
                'tax_amount' => 0,
                'grand_total' => $taxable,
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
                'status' => 'paid',
                'customer_name' => $customer?->name ?? 'Umum',
            ]);

            $transaction->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();

            foreach ($lines as $line) {
                $product = $line['product'];
                $qty = $line['qty'];

                // HPP dari batch sungguhan. Baris transaksi harus menyimpan
                // buy_price yang sama dengan yang dipakai FinancialReportService,
                // kalau tidak maka panel HPP dan Laba di halaman Analitik
                // menghitung sesuatu yang tidak pernah terjadi di kasir.
                $lineHpp = $cogs->calculate($product->id, $qty, $method, null, $transaction);

                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'price' => $product->sell_price,
                    'buy_price' => $lineHpp / max(1, $qty),
                ]);
            }

            return 1;
        });
    }
}
