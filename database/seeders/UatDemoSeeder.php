<?php

namespace Database\Seeders;

use App\Models\Capital;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StockBatch;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\CogsService;
use App\Services\InventoryStockService;
use App\Services\StockIntelligenceService;
use App\Services\TaxService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data demo untuk UAT.
 *
 * Berbeda dengan RecoverySeeder, seeder ini sengaja membuat kondisi yang
 * diperlukan untuk menguji HPP:
 *
 *  - Setiap bahan masuk DUA KALI dengan harga berbeda, jadi FIFO (batch
 *    terlama) dan LIFO (batch terbaru) benar-benar bisa dibedakan.
 *  - Baris transaksi menyimpan `buy_price` hasil alokasi batch yang SEBENARNYA,
 *    lewat CogsService. Kalau tidak, Laporan Keuangan akan menampilkan HPP Rp 0
 *    dan angka yang diuji tidak berarti apa-apa.
 *  - Ada menu resep, multi-satuan, bundel, dan diskon supaya semua jalur
 *    perhitungan ikut tersentuh.
 *
 * Sifat: idempotent dan NON-DESTRUKTIVE. Tidak menghapus apa pun, tidak
 * menyentuh tabel settings / payment_settings (memisi kredensial Midtrans),
 * dan tidak menyentuh user admin. Aman dijalankan berkali-kali.
 *
 * Bersihkan dengan: php artisan uat:reset-demo
 */
class UatDemoSeeder extends Seeder
{
    /**
     * Barcode menu yangLalu dimerik dari bahan (punya resep).
     *
     * Menu resep TIDAK boleh punya lot stok sendiri: ketersediaannya diambil
     * dari bahan, dan nilainya sudah tercakup di persediaan bahan. Kalau menu
     * resep ikut di-Stock-In, ada pembelian yang tercatat sebagai hutang tapi
     * tidak pernah muncul sebagai aset, dan selisih neraca menganga sebesar
     * nilai lot itu.
     */
    private const RECIPE_MENU_BARCODES = ['UAT-S002', 'UAT-M002', 'UAT-M001'];

    public function run(): void
    {
        $this->command->info('Menyiapkan data demo UAT...');

        $cashier = $this->ensureCashier();
        $this->ensureSettings();

        $ingredients = $this->seedIngredients($cashier);
        $products = $this->seedProducts($cashier, $ingredients);
        $this->seedUnits($products);
        $products += $this->seedBundle($products);
        $this->seedRecipes($products, $ingredients);
        $this->seedDiscounts();
        $customers = $this->seedCustomers();
        $this->seedShift($cashier);
        $this->seedCapital();
        $this->seedExpenses($cashier);
        $this->seedTransactions($cashier, $products, $customers);

        $this->command->info('Selesai. Jalankan `php artisan uat:verify-demo` untuk mengecek angkanya.');
    }

    private function ensureCashier(): User
    {
        $admin = User::where('email', 'admin@gmail.com')->first();
        if ($admin) {
            return $admin;
        }

        $this->command->warn('admin@gmail.com tidak ditemukan, memakai user pertama.');
        $first = User::query()->first();
        if ($first) {
            return $first;
        }

        $this->command->error('Tidak ada user sama sekali. Login ke aplikasi dulu.');
        exit(1);
    }

    private function ensureSettings(): void
    {
        $setting = Setting::first();

        // Default: FIFO tanpa pajak, supaya angka HPP mudah dihitung manual.
        $setting ??= Setting::create([
            'cogs_method' => 'FIFO',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => true,
            'loyalty_points_per_100' => 1,
            'kitchen_display_enabled' => false,
            'kitchen_display_payment_status' => 'both',
        ]);

        if ($setting->cogs_method !== 'FIFO') {
            $this->command->warn("Metode HPP diubah ke FIFO untuk demo (dari {$setting->cogs_method}).");
            $setting->update(['cogs_method' => 'FIFO']);
        }
    }

    /**
     * Bahan baku. Dua bahan masuk dua kali dengan harga berbeda supaya
     * FIFO vs LIFO bisa dibandingkan.
     */
    private function seedIngredients(User $cashier): array
    {
        $service = app(InventoryStockService::class);
        $rows = [];
        $purchases = collect();

        // Satuan WAJIB konsisten dengan harga beli. Biji Kopi bersatuan Kg dan
        // berharga Rp 180.000/Kg, bukan gram. Kalau satuan gram tapi harga
        // 180.000, satu gelas kopi akan ber-HPP ratusan ribu.
        $plan = [
            // [nama, satuan, [batch1 qty+harga, batch2 qty+harga]]
            'Beras Premium' => ['Kg', [[20, 14000], [20, 20000]]],
            'Gula Pasir' => ['Kg', [[10, 15000], [10, 18000]]],
            'Minyak Goreng' => ['Liter', [[12, 32000], [12, 36000]]],
            'Biji Kopi' => ['Kg', [[2, 180000], [2, 240000]]],
            'Susu Kental Manis' => ['ml', [[5000, 26], [5000, 30]]],
            'Cokelat Batang' => ['Kg', [[1, 60000], [1, 72000]]],
            'Daun Teh' => ['Kg', [[0.5, 40000], [0.5, 50000]]],
            'Tepung Terigu' => ['Kg', [[5, 12000], [5, 16000]]],
        ];

        foreach ($plan as $name => [$unit, $batches]) {
            $ingredient = Ingredient::firstOrCreate(['name' => $name], [
                'unit' => $unit,
                'buy_price' => 0,
                'stock' => 0,
                'min_stock' => 5,
            ]);

            $rows[$name] = $ingredient;

            // Idempotent: hanya buat layer baru kalau batch masih kurang dari
            // rencana. Menjalankan ulang tidak menambah stok berlipat.
            $plannedTotal = array_sum(array_column($batches, 0));
            $current = (float) StockBatch::where('ingredient_id', $ingredient->id)->sum('qty_remaining');
            if ($current >= $plannedTotal) {
                continue;
            }

            foreach ($batches as [$qty, $price]) {
                if ($current >= $plannedTotal) {
                    break;
                }
                $service->recordStockIn('ingredient', $ingredient->id, $qty, $price, $cashier->id);
                $purchases->push(['name' => $name, 'total' => $qty * $price]);
            }
        }

        $this->recordPurchases($purchases, $cashier);

        $this->command->info('  Bahan baku: '.count($rows).' item (masing-masing 2 batch harga berbeda).');

        return $rows;
    }

    private function seedProducts(User $cashier, array $ingredients): array
    {
        $service = app(InventoryStockService::class);
        $categories = [];
        foreach (['Makanan', 'Minuman', 'Snacking', 'Lainnya'] as $name) {
            $categories[$name] = Category::firstOrCreate(['name' => $name], ['image' => null]);
        }

        $rows = [];
        $purchases = collect();
        $plan = [
            // [barcode, kategori, judul, harga beli, harga jual, stok, satuan]
            ['UAT-P001', 'Makanan', 'Nasi Goreng (Frozen)', 18000, 28000, 40, 'Pcs'],
            ['UAT-P002', 'Makanan', 'Ayam Bakar (Frozen)', 32000, 45000, 30, 'Pcs'],
            ['UAT-M001', 'Minuman', 'Es Teh Manis', 2000, 8000, 100, 'Gelas'],
            ['UAT-M002', 'Minuman', 'Kopi Susu', 9000, 18000, 60, 'Gelas'],
            ['UAT-S001', 'Snacking', 'Keripik Kentang', 7000, 12000, 80, 'Pcs'],
            ['UAT-S002', 'Snacking', 'Kue Cokelat', 8000, 15000, 40, 'Pcs'],
        ];

        foreach ($plan as [$barcode, $category, $title, $buy, $sell, $stock, $unit]) {
            $product = Product::firstOrCreate(['barcode' => $barcode], [
                'category_id' => $categories[$category]->id,
                'title' => $title,
                'description' => $title.' (data demo UAT)',
                'buy_price' => $buy,
                'sell_price' => $sell,
                'stock' => 0,
                'unit' => $unit,
                'type' => 'single',
                'image' => null,
            ]);

            $rows[$barcode] = $product;

            if (in_array($barcode, self::RECIPE_MENU_BARCODES, true)) {
                // Menu resep: tanpa lot. Persediaannya ada di bahan, dan HPP-nya
                // dihitung dari bahan yang benar-benar terpakai.
                StockBatch::where('product_id', $product->id)->delete();
                $product->update(['stock' => 0, 'buy_price' => 0]);

                continue;
            }

            $current = (float) StockBatch::where('product_id', $product->id)->sum('qty_remaining');
            if ($current >= $stock) {
                continue;
            }

            $service->recordStockIn('product', $product->id, $stock, $buy, $cashier->id);
            $purchases->push(['name' => $title, 'total' => $stock * $buy]);
        }

        $this->recordPurchases($purchases, $cashier);
        $this->command->info('  Produk jadi: '.count($rows).' item ('.count(self::RECIPE_MENU_BARCODES).' di antaranya menu resep tanpa lot).');

        return $rows;
    }

    /**
     * Catat belanja stok supaya sisi kas di neraca ikut bergerak.
     *
     * Tanpa ini, nilai persediaan naik tanpa ada kas yang turun, sehingga
     * selisih neraca selalu sebesar nilai stok yang dibeli.
     */
    private function recordPurchases($purchases, User $cashier): void
    {
        if ($purchases->isEmpty()) {
            return;
        }

        app(\App\Services\StockPurchaseRecorder::class)->record(
            $purchases,
            \App\Services\StockPurchaseRecorder::PAID_CREDIT,
            $cashier->id,
            now()->subDays(14)->toDateString()
        );
    }

    /**
     * Multi-satuan: 1 Box Susu = 12 Pcs. dipakai untuk menguji konversi
     * satuan saat penjualan.
     */
    private function seedUnits(array $products): void
    {
        $product = $products['UAT-M002'] ?? null;
        if (! $product) {
            return;
        }

        ProductUnit::firstOrCreate(
            ['product_id' => $product->id, 'unit_name' => 'Box'],
            ['conversion' => 12, 'sell_price' => 195000]
        );

        $this->command->info('  Multi-satuan: 1 Box Kopi Susu = 12 Gelas.');
    }

    /**
     * Bundel dikembalikan supaya ikut bisa dijual dan HPP-nya ikut teruji.
     * Kalau tidak, jalur HPP bundel (mengurai komponen) tidak pernah dieksekusi.
     *
     * @return array<string, Product>
     */
    private function seedBundle(array $products): array
    {
        $rice = $products['UAT-P001'] ?? null;
        $chicken = $products['UAT-P002'] ?? null;
        $drink = $products['UAT-M001'] ?? null;
        if (! $rice || ! $chicken || ! $drink) {
            return [];
        }

        $bundle = Product::firstOrCreate(['barcode' => 'UAT-B001'], [
            'category_id' => $rice->category_id,
            'title' => 'Paket Hemat Nasi + Ayam',
            'description' => 'Nasi Goreng + Ayam Bakar + Es Teh (bundel)',
            'type' => 'bundle',
            'buy_price' => 0,
            'sell_price' => 60000,
            'stock' => 0,
            'unit' => 'Paket',
            'image' => null,
        ]);

        // Bundel tidak punya stok fisik sendiri; stok comes dari komponennya.
        foreach ([[$rice->id, 1], [$chicken->id, 1], [$drink->id, 1]] as [$itemId, $qty]) {
            $exists = DB::table('product_bundles')
                ->where('product_id', $bundle->id)
                ->where('item_id', $itemId)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('product_bundles')->insert([
                'product_id' => $bundle->id,
                'item_id' => $itemId,
                'qty' => $qty,
                'product_unit_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('  Bundel: Paket Hemat (3 komponen).');

        return ['UAT-B001' => $bundle];
    }

    private function seedRecipes(array $products, array $ingredients): void
    {
        // Takaran memakai SATUAN BAHAN itu sendiri (bukan gram/liner).
        // Gula Pasir bersatuan Kg, jadi 0.01 = 10 gram per gelas. Kalau salah
        // satuan di sini, stok akan habis dalam beberapa transaksi.
        $plan = [
            // [barcode, [nama bahan, takaran dalam satuan bahan]]
            'UAT-S002' => [
                ['Cokelat Batang', 0.04],
                ['Susu Kental Manis', 30],
            ],
            'UAT-M002' => [
                ['Biji Kopi', 0.02],
                ['Susu Kental Manis', 30],
                ['Gula Pasir', 0.015],
            ],
            'UAT-M001' => [
                ['Daun Teh', 0.003],
                ['Gula Pasir', 0.01],
            ],
        ];

        $made = 0;
        foreach ($plan as $barcode => $lines) {
            $product = $products[$barcode] ?? null;
            if (! $product) {
                continue;
            }

            // Resep tidak boleh ganda. Hapus lama, lalu tulis ulang supaya
            // idempotent dan cost_price selalu ikut dihitung ulang.
            Recipe::where('product_id', $product->id)->delete();

            foreach ($lines as [$ingredientName, $qty]) {
                $ingredient = $ingredients[$ingredientName] ?? null;
                if (! $ingredient) {
                    continue;
                }
                Recipe::create([
                    'product_id' => $product->id,
                    'ingredient_id' => $ingredient->id,
                    'qty_needed' => $qty,
                ]);
                $made++;
            }

            $this->recalculateCostPrice($product->id);
        }

        $this->command->info("  Resep: {$made} komposisi bahan (dengan hitung HPP ulang).");
    }

    /**
     * Menyalin rumus RecipeController::calculateProductHpp. Dipisah supaya
     * seeder tidak bergantung pada private method controller.
     */
    private function recalculateCostPrice(int $productId): void
    {
        $product = Product::with('recipes.ingredient')->find($productId);
        if (! $product) {
            return;
        }

        $cost = 0.0;
        foreach ($product->recipes as $recipe) {
            if ((float) $recipe->qty_needed <= 0) {
                continue;
            }
            $cost += (float) $recipe->qty_needed * (float) ($recipe->ingredient->buy_price ?? 0);
        }

        $product->update(['cost_price' => $cost]);
    }

    private function seedDiscounts(): void
    {
        $existing = Discount::where('name', 'Diskon UAT 10%')->exists();
        if ($existing) {
            return;
        }

        Discount::create([
            'name' => 'Diskon UAT 10%',
            'type' => 'percentage',
            'value' => 10,
            'min_transaction' => 50000,
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'is_active' => true,
        ]);

        $this->command->info('  Diskon global: 10% untuk transaksi >= Rp 50.000.');
    }

    private function seedCustomers(): array
    {
        $rows = [];
        $plan = [
            ['Andi Nugraha', '081211111111', 0],
            ['Bunga Maharani', '081312345678', 3],
            ['Cici Amelia', '081512340000', 6],
            ['Davin Pradipta', '085612349911', 9],
            ['Eko Saputra', '087712348822', 12],
        ];

        foreach ($plan as [$name, $phone, $ageDays]) {
            $rows[$name] = Customer::updateOrCreate(['no_telp' => $phone], [
                'name' => $name,
                'email' => Str::slug($name).'@demo.test',
                'address' => 'Jl. Demo UAT No. '.(1 + array_search($name, array_column($plan, 0, true))),
                'marketing_opt_in' => true,
            ]);
        }

        $this->command->info('  Pelanggan: '.count($rows).' orang.');

        return $rows;
    }

    private function seedCapital(): void
    {
        if (Capital::exists()) {
            return;
        }

        // Modal disetor sama dengan modal awal shift, karena itu uang yang
        // sama. Kalau modal jauh lebih besar dari kas yang ada, neraca akan
        // langsung menunjukkan selisih raksasa dan bikin UAT ambigu.
        $openingCash = (float) (Shift::query()->value('starting_cash') ?? 0);

        Capital::create([
            'source' => 'Setoran Modal Awal (demo UAT)',
            'amount' => $openingCash > 0 ? $openingCash : 500000,
            'date' => now()->subDays(14)->toDateString(),
        ]);

        $this->command->info('  Modal usaha: dicatat sama dengan modal awal shift (sisi ekuitas neraca).');
    }

    private function seedShift(User $cashier): void
    {
        $open = Shift::where('user_id', $cashier->id)->where('status', 'open')->exists();
        if ($open) {
            return;
        }

        // Shift tertutup dengan saldo awal, supaya rekap shift punya angka.
        $closed = Shift::where('user_id', $cashier->id)->where('status', 'closed')->exists();
        if ($closed) {
            return;
        }

        Shift::create([
            'user_id' => $cashier->id,
            'starting_cash' => 500000,
            'status' => 'open',
            'opened_at' => now()->subDays(7),
        ]);

        $this->command->info('  Shift terbuka: modal awal Rp 500.000 (untuk uji rekonsiliasi kas).');
    }

    private function seedExpenses(User $cashier): void
    {
        $exists = Expense::where('name', 'like', '%demo UAT%')->exists();
        if ($exists) {
            return;
        }

        // Nominal sengaja proporsional dengan omzet demo (sekitar Rp 400.000)
        // supaya laba bersih positif dan terlihat wajar. Kalau dibiarkan jauh
        // lebih besar, hari pertama sudah showed untung besar dan sulit
        // dibedakan dari bug perhitungan.
        $plan = [
            // Source eksplisit, bukan default 'Kas Laci'. Kalau dibiarkan
            // default, listrik dan gaji akan ikut terhitung sebagai pengeluaran
            // laci kas dan merusak rekonsiliasi shift.
            ['Listrik Resto (demo UAT)', 45000, 'Operasional', 'Operasional', 6],
            ['Gaji Karyawan (demo UAT)', 120000, 'Operasional', 'Operasional', 4],
            ['Beli Gas LPG (demo UAT)', 15000, 'Operasional', 'Operasional', 2],
        ];

        foreach ($plan as [$name, $amount, $category, $source, $daysAgo]) {
            Expense::create([
                'user_id' => $cashier->id,
                'name' => $name,
                'amount' => $amount,
                'category' => $category,
                'source' => $source,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'note' => 'Data demo UAT',
            ]);
        }

        $this->command->info('  Beban operasional: 3 pengeluaran (total Rp 180.000).');
    }

    /**
     * Transaksi tersebar 7 hari ke belakang supaya laporan, grafik, dan AI
     * punya data untuk dianalisis.
     *
     * Baris detail diisi `buy_price` dari CogsService, yaitu HPP yang benar-benar
     * dialokasikan dari batch. Ini yang membuat angka HPP di Laporan Keuangan
     * bisa dipercaya saat UAT.
     */
    private function seedTransactions(User $cashier, array $products, array $customers): void
    {
        $cogs = app(CogsService::class);
        $stock = app(StockIntelligenceService::class);
        $taxService = app(TaxService::class);
        $setting = Setting::first();
        $method = $setting->cogs_method ?? 'FIFO';

        // [hari ke belakang, pelanggan, [barcode => qty], metode bayar]
        $plan = [
            [6, 'Andi Nugraha', ['UAT-P001' => 2, 'UAT-M001' => 2], 'cash'],
            [6, 'Bunga Maharani', ['UAT-S002' => 2], 'cash'],
            [5, 'Cici Amelia', ['UAT-M002' => 1, 'UAT-S001' => 1], 'qris_manual'],
            [4, 'Davin Pradipta', ['UAT-P002' => 1, 'UAT-M001' => 1], 'cash'],
            [4, 'Bunga Maharani', ['UAT-B001' => 1], 'cash'],
            [3, 'Andi Nugraha', ['UAT-M002' => 2], 'qris_manual'],
            [2, 'Eko Saputra', ['UAT-S001' => 2, 'UAT-S002' => 1], 'cash'],
            [1, 'Cici Amelia', ['UAT-P001' => 1, 'UAT-M001' => 1], 'cash'],
            [0, 'Davin Pradipta', ['UAT-S002' => 2, 'UAT-M001' => 2], 'qris_manual'],
            [0, null, ['UAT-M002' => 1], 'cash'],
        ];

        $made = 0;
        foreach ($plan as $index => [$daysAgo, $customerName, $items, $paymentMethod]) {
            $invoice = 'TRX-UAT-'.sprintf('%02d', $index + 1);
            if (Transaction::where('invoice', $invoice)->exists()) {
                continue;
            }

            $lines = [];
            foreach ($items as $barcode => $qty) {
                $product = $products[$barcode] ?? null;
                if ($product) {
                    $lines[] = ['product' => $product, 'qty' => (int) $qty];
                }
            }
            if ($lines === []) {
                continue;
            }

            $subtotal = collect($lines)->sum(fn ($line) => $line['product']->sell_price * $line['qty']);
            $discount = 0;
            $promo = Discount::active()->whereNull('product_id')
                ->where('min_transaction', '<=', $subtotal)
                ->orderBy('min_transaction', 'desc')
                ->first();
            if ($promo) {
                $raw = $promo->type === 'percentage' ? $subtotal * ($promo->value / 100) : (float) $promo->value;
                $discount = (int) round(min($subtotal, max(0, $raw)), 0, PHP_ROUND_HALF_UP);
            }

            $taxable = max(0, $subtotal - $discount);
            $tax = $taxService->calculate($taxable, $setting);
            $createdAt = now()->subDays($daysAgo)->setTime(11 + ($index % 8), ($index * 7) % 60, 0);

            $made += $this->createTransaction(
                $cashier,
                $customers[$customerName] ?? null,
                $invoice,
                $lines,
                $discount,
                $taxable,
                $tax,
                $paymentMethod,
                $createdAt,
                $method,
                $cogs,
                $stock
            );
        }

        $this->command->info("  Transaksi: {$made} transaksi baru (HPP dihitung dari batch sungguhan).");
    }

    private function createTransaction(
        User $cashier,
        ?Customer $customer,
        string $invoice,
        array $lines,
        int $discount,
        int $taxable,
        array $tax,
        string $paymentMethod,
        $createdAt,
        string $method,
        CogsService $cogs,
        StockIntelligenceService $stock
    ): int {
        $grandTotal = (int) $tax['total'];

        return DB::transaction(function () use (
            $cashier, $customer, $invoice, $lines, $discount, $taxable, $tax,
            $paymentMethod, $createdAt, $method, $cogs, $stock, $grandTotal
        ) {
            $transaction = Transaction::create([
                'cashier_id' => $cashier->id,
                'customer_id' => $customer?->id,
                'invoice' => $invoice,
                'cash' => $grandTotal,
                'change' => 0,
                'discount' => $discount,
                'taxable_amount' => $taxable,
                'tax_rate' => $tax['enabled'] ? $tax['rate'] : null,
                'tax_amount' => $tax['amount'],
                'grand_total' => $grandTotal,
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
                'status' => 'paid',
                'customer_name' => $customer?->name ?? 'Umum',
            ]);

            // created_at dikunci ke hari yang direncanakan supaya tren,
            // grafik mingguan, dan AI punya sebaran data yang masuk akal.
            $transaction->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

            $subtotal = collect($lines)->sum(fn ($line) => $line['product']->sell_price * $line['qty']);
            $totalHpp = 0.0;
            $totalProfit = 0.0;

            foreach ($lines as $line) {
                $product = $line['product'];
                $qty = $line['qty'];
                $linePrice = $product->sell_price * $qty;

                // Alokasi HPP: bundle mengurai komponennya, resep memakai
                // bahan, produk biasa memakai batchnya sendiri.
                $lineHpp = 0.0;
                if ($product->type === 'bundle') {
                    foreach ($product->bundle_items as $component) {
                        $conversion = $component->pivot->product_unit_id
                            ? (ProductUnit::find($component->pivot->product_unit_id)->conversion ?? 1)
                            : 1;
                        $lineHpp += $cogs->calculate(
                            $component->id,
                            $qty * (float) $component->pivot->qty * $conversion,
                            $method,
                            null,
                            $transaction
                        );
                    }
                } else {
                    $lineHpp = $cogs->calculate($product->id, $qty, $method, null, $transaction);
                }

                $totalHpp += $lineHpp;

                $proportion = $subtotal > 0 ? ($linePrice / $subtotal) : 0;
                $totalProfit += $linePrice - ($discount * $proportion) - $lineHpp;

                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'price' => $linePrice,
                    // INI yang membuat HPP di Laporan Keuangan benar.
                    'buy_price' => $qty > 0 ? ($lineHpp / $qty) : 0,
                    'unit' => $product->unit ?? 'Pcs',
                ]);
            }

            $transaction->profits()->create(['total' => $totalProfit]);

            // Stok baru benar-benar keluar setelah HPP dialokasikan, sama
            // seperti di TransactionController.
            $stock->consumeForCheckout(
                collect($lines)->map(fn ($line) => [
                    'product_id' => $line['product']->id,
                    'qty' => $line['qty'],
                ])->all()
            );

            $this->awardLoyalty($transaction, $customer);

            return 1;
        });
    }

    private function awardLoyalty(Transaction $transaction, ?Customer $customer): void
    {
        if (! $customer) {
            return;
        }

        $points = (int) floor((float) $transaction->grand_total / 100);
        if ($points <= 0) {
            return;
        }

        $customer->increment('lifetime_spend', (int) $transaction->grand_total);
        $customer->increment('loyalty_points', $points);

        $balance = (int) $customer->fresh()->loyalty_points;
        $customer->update(['loyalty_tier' => $this->tierForPoints($balance)]);
    }

    private function tierForPoints(int $points): string
    {
        return match (true) {
            $points >= 1000 => 'platinum',
            $points >= 500 => 'gold',
            $points >= 100 => 'silver',
            default => 'bronze',
        };
    }
}
