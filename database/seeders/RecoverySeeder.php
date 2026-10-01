<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Pulihkan workspace pengembangan setelah data lokal terhapus.
 *
 * Sifatnya idempotent (aman dijalankan berkali-kali) dan TIDAK memotong tabel
 * apa pun, berbeda dengan SampleDataSeeder yang bersifat destruktif.
 */
class RecoverySeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Membuat default setting sistem...');
        $this->createSettings();

        $this->command->info('Membuat role kitchen...');
        $this->createKitchenRole();

        $this->command->info('Membuat user dapur...');
        $this->createKitchenUser();

        if (Customer::count() === 0 && Product::count() === 0) {
            $this->command->info('Membangun data master...');
            $this->createMasterData();
        } else {
            $this->command->info('Data master sudah ada, dilewati.');
            $this->syncMissingBatches();
            // Transaksi tetap dijamin: idempotent lewat invoice deterministik.
            $this->createRecoveryTransactions();
        }

        $this->command->info('Menandai pelanggan contoh sebagai opt-in marketing...');
        Customer::where('marketing_opt_in', false)->update([
            'marketing_opt_in' => true,
            'crm_opt_out_at' => null,
        ]);

        $this->command->info('Workspace pemulihan selesai.');
    }

    /**
     * Baris `settings` menentukan method HPP, pajak, dan loyalitas global.
     * Tanpa baris ini aplikasi masih jalan (ada fallback), tapi halaman
     * Pengaturan tidak punya nilai tersimpan untuk diedit.
     */
    private function createSettings(): void
    {
        if (Setting::query()->exists()) {
            return;
        }

        Setting::create([
            'cogs_method' => 'AVERAGE',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => true,
            'loyalty_points_per_100' => 1,
            'kitchen_display_enabled' => false,
            'kitchen_display_payment_status' => 'both',
        ]);
    }

    private function createKitchenRole(): void
    {
        $role = Role::firstOrCreate(['name' => 'kitchen', 'guard_name' => 'web']);

        $permissions = Permission::whereIn('name', [
            'dashboard.index',
            'dashboard-access',
            'kitchen-display.index',
            'transactions-access',
        ])->get();

        $role->syncPermissions($permissions);
    }

    private function createKitchenUser(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'dapur@gmail.com'],
            [
                'name' => 'Dapur',
                'password' => Hash::make('password'),
            ]
        );

        if ($role = Role::where('name', 'kitchen')->first()) {
            $user->assignRole($role);
        }
    }

    private function createMasterData(): void
    {
        $categories = [];
        foreach ([
            'Minuman' => 'Aneka minuman segar dan kemasan',
            'Makanan Berat' => 'Makanan siap saji dan frozen food',
            'Makanan Ringan' => 'Camilan dan snack kemasan',
            'Roti & Kue' => 'Roti segar dan aneka kue',
        ] as $name => $description) {
            $categories[$name] = Category::firstOrCreate(['name' => $name], [
                'description' => $description,
                // null = frontend memakai fallback avatar, bukan file yang
                // tidak ada (default.jpg tidak pernah ada di repo).
                'image' => null,
            ]);
        }

        $products = [];
        foreach ([
            ['MNM-0001', 'Minuman', 'Aqua Botol 600ml', 3000, 5000, 200],
            ['MNM-0002', 'Minuman', 'Teh Botol Sosro 450ml', 4000, 6000, 150],
            ['MNM-0003', 'Minuman', 'Kopi Susu Gula Aren', 12000, 18000, 80],
            ['MKN-0001', 'Makanan Berat', 'Nasi Goreng Frozen', 15000, 22000, 40],
            ['MKN-0002', 'Makanan Berat', 'Ayam Goreng Frozen', 25000, 38000, 35],
            ['SNK-0001', 'Makanan Ringan', 'Chitato Original 68g', 8000, 12000, 120],
            ['SNK-0002', 'Makanan Ringan', 'Indomie Goreng', 2500, 3500, 300],
            ['RTI-0001', 'Roti & Kue', 'Roti Tawar Sari Roti', 12000, 16000, 50],
            ['RTI-0002', 'Roti & Kue', 'Donat Coklat', 5000, 8000, 30],
        ] as [$barcode, $category, $title, $buy, $sell, $stock]) {
            $product = Product::firstOrCreate(['barcode' => $barcode], [
                'category_id' => $categories[$category]->id,
                'title' => $title,
                'description' => $title,
                'buy_price' => $buy,
                'sell_price' => $sell,
                'stock' => 0,
                'image' => null,
            ]);

            $products[$barcode] = $product;

            // Stock harus masuk lewat InventoryStockService supaya master
            // stock, stock_movement, dan stock_batches tetap sinkron.
            // Menulis `stock` langsung akan membuat batch kosong dan
            // inventory:reconcile melaporkan selisih.
            if (! $product->wasRecentlyCreated) {
                continue;
            }

            app(\App\Services\InventoryStockService::class)->recordStockIn(
                'product',
                $product->id,
                $stock,
                $buy,
                (int) User::query()->value('id'),
            );
        }

        $customers = [];
        foreach ([
            ['Andi Nugraha', '081211111111', 'Jl. Melati No. 21, Bandung'],
            ['Bunga Maharani', '081312345678', 'Jl. Mawar No. 5, Jakarta'],
            ['Cici Amelia', '081512340000', 'Jl. Anggrek No. 17, Surabaya'],
            ['Davin Pradipta', '085612349911', 'Jl. Kenanga No. 2, Yogyakarta'],
            ['Eko Saputra', '087712348822', 'Jl. Cemara No. 45, Semarang'],
        ] as [$name, $phone, $address]) {
            $customers[$name] = Customer::updateOrCreate(['no_telp' => $phone], [
                'name' => $name,
                'email' => Str::slug($name).'@example.com',
                'address' => $address,
                'marketing_opt_in' => true,
            ]);
        }

        $this->createTransactions($customers, $products);
    }

    /**
     * Produk yang master stock-nya sudah ada tetapi batch-nya kosong akan
     * dianggap selisih oleh `inventory:reconcile`, sehingga HPP tidak punya
     * lapisan untuk diambil. Steps ini membuat layer awal dengan harga beli
     * produk, lalu stock dikembalikan ke nilai aslinya.
     */
    private function syncMissingBatches(): void
    {
        $service = app(\App\Services\InventoryStockService::class);
        $userId = (int) User::query()->value('id');
        $fixed = 0;

        foreach (Product::query()->get() as $product) {
            $stock = (float) $product->stock;
            if ($stock <= 0) {
                continue;
            }

            $hasBatch = \App\Models\StockBatch::where('product_id', $product->id)->exists();
            $batchTotal = (float) \App\Models\StockBatch::where('product_id', $product->id)->sum('qty_remaining');
            if ($hasBatch && abs($batchTotal - $stock) < 0.000001) {
                continue;
            }

            $product->update(['stock' => 0]);
            $service->setActualQuantity('product', $product->id, $stock, $userId, 'Pemulihan data awal');
            $fixed++;
        }

        if ($fixed > 0) {
            $this->command->info("Sinkronisasi batch stok: {$fixed} produk.");
        }
    }

    /**
     * Bangun transaksi contoh dari data master yang sudah ada.
     * Idempotent: melewati invoice TRX-RECOVERY-* yang sudah pernah dibuat.
     */
    private function createRecoveryTransactions(): void
    {
        $products = [];
        foreach (['MNM-0001', 'MNM-0002', 'MNM-0003', 'MKN-0001', 'MKN-0002', 'SNK-0001', 'SNK-0002', 'RTI-0001', 'RTI-0002'] as $barcode) {
            if ($product = Product::where('barcode', $barcode)->first()) {
                $products[$barcode] = $product;
            }
        }

        $customers = [];
        foreach (Customer::whereIn('no_telp', ['081211111111', '081312345678', '081512340000', '085612349911', '087712348822'])->get() as $customer) {
            $customers[$customer->name] = $customer;
        }

        $this->createTransactions($customers, $products);
    }

    private function createTransactions(array $customers, array $products): void
    {
        $cashier = User::where('email', 'admin@gmail.com')->first() ?? User::first();
        if (! $cashier) {
            return;
        }

        // Nominal sengaja disebar agar tier pelanggan bervariasi
        // (bronze / silver / gold) supaya segment berbasis tier bisa diuji.
        $blueprints = [
            ['Andi Nugraha', 0, 60000, [['MNM-0001', 3], ['MKN-0002', 1]]],        // 53.000 -> gold
            ['Bunga Maharani', 0, 35000, [['MKN-0001', 1], ['RTI-0002', 1]]],     // 30.000 -> silver
            ['Cici Amelia', 0, 10000, [['SNK-0002', 2]]],                          //  7.000 -> bronze
            ['Davin Pradipta', 0, 25000, [['MNM-0002', 1], ['RTI-0001', 1]]],      // 22.000 -> silver
            ['Eko Saputra', 0, 20000, [['MNM-0003', 1]]],                         // 18.000 -> silver
            [null, 0, 20000, [['SNK-0001', 1]]],
        ];

        foreach ($blueprints as $index => [$customerName, $discount, $cash, $items]) {
            // Invoice deterministik supaya seeder benar-benar idempotent:
            // menjalankan ulang tidak menambah transaksi ganda.
            $invoice = 'TRX-RECOVERY-'.sprintf('%02d', $index + 1);

            if (Transaction::where('invoice', $invoice)->exists()) {
                continue;
            }

            $lines = collect($items)->map(function ($item) use ($products) {
                $product = $products[$item[0]] ?? null;

                return $product ? ['product' => $product, 'qty' => $item[1]] : null;
            })->filter()->values();

            if ($lines->isEmpty()) {
                continue;
            }
            $gross = $lines->sum(fn ($line) => $line['product']->sell_price * $line['qty']);
            $grandTotal = max(0, $gross - $discount);
            $cashPaid = max($grandTotal, $cash);
            $totalHpp = 0.0;

            $transaction = DB::transaction(function () use ($cashier, $customerName, $customers, $discount, $grandTotal, $cashPaid, $invoice, $lines) {
                $transaction = Transaction::create([
                    'cashier_id' => $cashier->id,
                    'customer_id' => $customerName ? ($customers[$customerName]->id ?? null) : null,
                    'invoice' => $invoice,
                    'cash' => $cashPaid,
                    'change' => $cashPaid - $grandTotal,
                    'discount' => $discount,
                    'taxable_amount' => $grandTotal,
                    'tax_amount' => 0,
                    'grand_total' => $grandTotal,
                    'payment_method' => 'cash',
                    'payment_status' => 'paid',
                    'status' => 'paid',
                ]);

                foreach ($lines as $line) {
                    $qty = (int) $line['qty'];
                    $linePrice = (int) $line['product']->sell_price * $qty;

                    // `buy_price` adalah HPP per unit yang disimpan sebagai
                    // jejak permanen. FinancialReportService menjumlahkan
                    // buy_price x qty sebagai HPP, jadi kolom ini WAJIB diisi.
                    // Kalau kosong, Laporan Keuangan akan menampilkan HPP Rp 0
                    // dan laba terlihat jauh lebih besar dari kenyataan.
                    $hpp = app(\App\Services\CogsService::class)->calculate(
                        $line['product']->id,
                        $qty,
                        \App\Models\Setting::first()?->cogs_method ?? 'AVERAGE',
                        null,
                        $transaction
                    );

                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $line['product']->id,
                        'qty' => $qty,
                        'price' => $linePrice,
                        'buy_price' => $qty > 0 ? ($hpp / $qty) : 0,
                    ]);

                    $totalHpp += $hpp;
                }

                // Baris laba disimpan sebagai satu agregat per transaksi.
                $subtotal = (int) $lines->sum(fn ($line) => $line['product']->sell_price * $line['qty']);
                $transaction->profits()->create([
                    'total' => $subtotal - $discount - $totalHpp,
                ]);

                return $transaction;
            });

            $this->syncCustomerSpend($transaction, $customers, $customerName);
        }
    }

    private function syncCustomerSpend(Transaction $transaction, array $customers, ?string $customerName): void
    {
        if (! $customerName || ! isset($customers[$customerName])) {
            return;
        }

        $customer = $customers[$customerName];
        $total = (int) $transaction->grand_total;
        $points = (int) floor($total / 100);

        $customer->increment('lifetime_spend', $total);
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
