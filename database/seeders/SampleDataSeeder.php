<?php
namespace Database\Seeders;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Profit;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SampleDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        Cart::truncate();
        TransactionDetail::truncate();
        Profit::truncate();
        Transaction::truncate();
        Product::truncate();
        Category::truncate();
        Customer::truncate();

        Schema::enableForeignKeyConstraints();

        // Ensure storage directories exist
        Storage::disk('public')->makeDirectory('category');
        Storage::disk('public')->makeDirectory('products');

        $this->command->info('Seeding customers...');
        $customers = $this->seedCustomers();

        $this->command->info('Seeding categories with images...');
        $categories = $this->seedCategories();

        $this->command->info('Seeding products with images...');
        $products = $this->seedProducts($categories);

        $this->command->info('Seeding transactions...');
        $this->seedTransactions($customers, $products);

        $this->command->info('Sample data seeding completed!');
    }

    /**
     * Download image from URL and save to storage
     */
    private function downloadImage(string $url, string $folder, string $filename): ?string
    {
        try {
            $this->command->info("  Downloading: {$filename}...");

            $response = Http::timeout(30)->get($url);

            if ($response->successful()) {
                $extension    = 'jpg';
                $fullFilename = $filename . '.' . $extension;

                Storage::disk('public')->put(
                    $folder . '/' . $fullFilename,
                    $response->body()
                );

                return $fullFilename;
            }
        } catch (\Exception $e) {
            $this->command->warn("  Failed to download {$filename}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Seed master customers.
     */
    private function seedCustomers(): Collection
    {
        $customers = collect([
            ['name' => 'Andi Nugraha', 'no_telp' => '6281211111111', 'address' => 'Jl. Melati No. 21, Bandung'],
            ['name' => 'Bunga Maharani', 'no_telp' => '6281312345678', 'address' => 'Jl. Mawar No. 5, Jakarta'],
            ['name' => 'Cici Amelia', 'no_telp' => '6281512340000', 'address' => 'Jl. Anggrek No. 17, Surabaya'],
            ['name' => 'Davin Pradipta', 'no_telp' => '6285612349911', 'address' => 'Jl. Kenanga No. 2, Yogyakarta'],
            ['name' => 'Eko Saputra', 'no_telp' => '6287712348822', 'address' => 'Jl. Cemara No. 45, Semarang'],
            ['name' => 'Fitri Lestari', 'no_telp' => '6282213345566', 'address' => 'Jl. Sakura No. 7, Medan'],
            ['name' => 'Gina Putri', 'no_telp' => '6281399887766', 'address' => 'Jl. Dahlia No. 12, Malang'],
            ['name' => 'Hendra Wijaya', 'no_telp' => '6285544332211', 'address' => 'Jl. Flamboyan No. 8, Denpasar'],
        ]);

        return $customers
            ->map(fn($customer) => Customer::create($customer))
            ->keyBy('name');
    }

    /**
     * Seed master categories with downloaded images.
     */
    private function seedCategories(): Collection
    {
        // Categories with Unsplash image URLs (direct download links)
        $categories = collect([
            [
                'name'        => 'Minuman',
                'description' => 'Aneka minuman segar dan kemasan',
                'image_url'   => 'https://images.unsplash.com/photo-1544145945-f90425340c7e?w=400&h=400&fit=crop',
            ],
            [
                'name'        => 'Makanan Ringan',
                'description' => 'Camilan dan snack kemasan',
                'image_url'   => 'https://images.unsplash.com/photo-1621939514649-280e2ee25f60?w=400&h=400&fit=crop',
            ],
            [
                'name'        => 'Makanan Berat',
                'description' => 'Makanan siap saji dan frozen food',
                'image_url'   => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=400&h=400&fit=crop',
            ],
            [
                'name'        => 'Produk Susu',
                'description' => 'Susu, yogurt, dan produk olahan susu',
                'image_url'   => 'https://images.unsplash.com/photo-1563636619-e9143da7973b?w=400&h=400&fit=crop',
            ],
            [
                'name'        => 'Roti & Kue',
                'description' => 'Roti segar dan aneka kue',
                'image_url'   => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?w=400&h=400&fit=crop',
            ],
            [
                'name'        => 'Bumbu & Rempah',
                'description' => 'Bumbu masak dan rempah-rempah',
                'image_url'   => 'https://images.unsplash.com/photo-1596040033229-a9821ebd058d?w=400&h=400&fit=crop',
            ],
            [
                'name'        => 'Perawatan Tubuh',
                'description' => 'Sabun, shampoo, dan perawatan diri',
                'image_url'   => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?w=400&h=400&fit=crop',
            ],
            [
                'name'        => 'Kebutuhan Rumah',
                'description' => 'Perlengkapan rumah tangga',
                'image_url'   => 'https://images.unsplash.com/photo-1583947215259-38e31be8751f?w=400&h=400&fit=crop',
            ],
        ]);

        return $categories->map(function ($category) {
            $slug  = Str::slug($category['name']);
            $image = $this->downloadImage(
                $category['image_url'],
                'category',
                'cat-' . $slug
            );

            return Category::create([
                'name'        => $category['name'],
                'description' => $category['description'],
                'image'       => $image ?? 'default.jpg',
            ]);
        })->keyBy('name');
    }

    /**
     * Seed products mapped to categories with downloaded images.
     */
    private function seedProducts(Collection $categories): Collection
    {
        // Products with Unsplash image URLs
        $products = collect([
            // Minuman
            ['category' => 'Minuman', 'barcode' => 'MNM-0001', 'title' => 'Aqua Botol 600ml', 'description' => 'Air mineral murni dalam kemasan botol praktis', 'buy_price' => 3000, 'sell_price' => 5000, 'stock' => 200, 'image_url' => 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=300&h=300&fit=crop'],
            ['category' => 'Minuman', 'barcode' => 'MNM-0002', 'title' => 'Teh Botol Sosro 450ml', 'description' => 'Teh manis segar dalam kemasan botol', 'buy_price' => 4000, 'sell_price' => 6000, 'stock' => 150, 'image_url' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=300&h=300&fit=crop'],
            ['category' => 'Minuman', 'barcode' => 'MNM-0003', 'title' => 'Kopi Susu Gula Aren', 'description' => 'Kopi susu dengan gula aren asli', 'buy_price' => 12000, 'sell_price' => 18000, 'stock' => 80, 'image_url' => 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?w=300&h=300&fit=crop'],
            ['category' => 'Minuman', 'barcode' => 'MNM-0004', 'title' => 'Jus Jeruk Segar 500ml', 'description' => 'Jus jeruk murni tanpa pengawet', 'buy_price' => 8000, 'sell_price' => 12000, 'stock' => 60, 'image_url' => 'https://images.unsplash.com/photo-1621506289937-a8e4df240d0b?w=300&h=300&fit=crop'],

            // Makanan Ringan
            ['category' => 'Makanan Ringan', 'barcode' => 'SNK-0001', 'title' => 'Chitato Original 68g', 'description' => 'Keripik kentang renyah rasa original', 'buy_price' => 8000, 'sell_price' => 12000, 'stock' => 120, 'image_url' => 'https://images.unsplash.com/photo-1566478989037-eec170784d0b?w=300&h=300&fit=crop'],
            ['category' => 'Makanan Ringan', 'barcode' => 'SNK-0002', 'title' => 'Oreo Vanilla 133g', 'description' => 'Biskuit sandwich dengan krim vanilla', 'buy_price' => 10000, 'sell_price' => 15000, 'stock' => 100, 'image_url' => 'https://images.unsplash.com/photo-1558961363-fa8fdf82db35?w=300&h=300&fit=crop'],
            ['category' => 'Makanan Ringan', 'barcode' => 'SNK-0003', 'title' => 'Indomie Goreng', 'description' => 'Mie instant goreng favorit Indonesia', 'buy_price' => 2500, 'sell_price' => 3500, 'stock' => 300, 'image_url' => 'https://images.unsplash.com/photo-1612929633738-8fe44f7ec841?w=300&h=300&fit=crop'],
            ['category' => 'Makanan Ringan', 'barcode' => 'SNK-0004', 'title' => 'Pringles Sour Cream', 'description' => 'Keripik kentang premium rasa sour cream', 'buy_price' => 25000, 'sell_price' => 35000, 'stock' => 50, 'image_url' => 'https://images.unsplash.com/photo-1613919113640-25732ec5e61f?w=300&h=300&fit=crop'],

            // Makanan Berat
            ['category' => 'Makanan Berat', 'barcode' => 'MKN-0001', 'title' => 'Nasi Goreng Frozen', 'description' => 'Nasi goreng siap saji tinggal panaskan', 'buy_price' => 15000, 'sell_price' => 22000, 'stock' => 40, 'image_url' => 'https://images.unsplash.com/photo-1603133872878-684f208fb84b?w=300&h=300&fit=crop'],
            ['category' => 'Makanan Berat', 'barcode' => 'MKN-0002', 'title' => 'Ayam Goreng Frozen', 'description' => 'Ayam goreng krispy siap goreng', 'buy_price' => 25000, 'sell_price' => 38000, 'stock' => 35, 'image_url' => 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?w=300&h=300&fit=crop'],
            ['category' => 'Makanan Berat', 'barcode' => 'MKN-0003', 'title' => 'Sosis Sapi 500g', 'description' => 'Sosis sapi premium isi 12 pcs', 'buy_price' => 35000, 'sell_price' => 48000, 'stock' => 45, 'image_url' => 'https://images.unsplash.com/photo-1587735243615-c03f25aaff15?w=300&h=300&fit=crop'],

            // Produk Susu
            ['category' => 'Produk Susu', 'barcode' => 'SSU-0001', 'title' => 'Ultra Milk 1L', 'description' => 'Susu UHT full cream', 'buy_price' => 16000, 'sell_price' => 21000, 'stock' => 80, 'image_url' => 'https://images.unsplash.com/photo-1550583724-b2692b85b150?w=300&h=300&fit=crop'],
            ['category' => 'Produk Susu', 'barcode' => 'SSU-0002', 'title' => 'Yogurt Cimory 250ml', 'description' => 'Yogurt drink rasa strawberry', 'buy_price' => 8000, 'sell_price' => 12000, 'stock' => 60, 'image_url' => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?w=300&h=300&fit=crop'],
            ['category' => 'Produk Susu', 'barcode' => 'SSU-0003', 'title' => 'Keju Cheddar 165g', 'description' => 'Keju cheddar slice praktis', 'buy_price' => 22000, 'sell_price' => 30000, 'stock' => 40, 'image_url' => 'https://images.unsplash.com/photo-1486297678162-eb2a19b0a32d?w=300&h=300&fit=crop'],

            // Roti & Kue
            ['category' => 'Roti & Kue', 'barcode' => 'RTI-0001', 'title' => 'Roti Tawar Sari Roti', 'description' => 'Roti tawar lembut tanpa kulit', 'buy_price' => 12000, 'sell_price' => 16000, 'stock' => 50, 'image_url' => 'https://images.unsplash.com/photo-1549931319-a545dcf3bc73?w=300&h=300&fit=crop'],
            ['category' => 'Roti & Kue', 'barcode' => 'RTI-0002', 'title' => 'Donat Coklat', 'description' => 'Donat lembut dengan topping coklat', 'buy_price' => 5000, 'sell_price' => 8000, 'stock' => 30, 'image_url' => 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=300&h=300&fit=crop'],
            ['category' => 'Roti & Kue', 'barcode' => 'RTI-0003', 'title' => 'Croissant Butter', 'description' => 'Croissant dengan butter premium', 'buy_price' => 10000, 'sell_price' => 15000, 'stock' => 25, 'image_url' => 'https://images.unsplash.com/photo-1555507036-ab1f4038808a?w=300&h=300&fit=crop'],

            // Bumbu & Rempah
            ['category' => 'Bumbu & Rempah', 'barcode' => 'BMB-0001', 'title' => 'Kecap Manis ABC 600ml', 'description' => 'Kecap manis kualitas premium', 'buy_price' => 18000, 'sell_price' => 25000, 'stock' => 70, 'image_url' => 'https://images.unsplash.com/photo-1472476443507-c7a5948772fc?w=300&h=300&fit=crop'],
            ['category' => 'Bumbu & Rempah', 'barcode' => 'BMB-0002', 'title' => 'Minyak Goreng 2L', 'description' => 'Minyak goreng sawit berkualitas', 'buy_price' => 28000, 'sell_price' => 38000, 'stock' => 90, 'image_url' => 'https://images.unsplash.com/photo-1474979266404-7eaacbcd87c5?w=300&h=300&fit=crop'],
            ['category' => 'Bumbu & Rempah', 'barcode' => 'BMB-0003', 'title' => 'Gula Pasir 1kg', 'description' => 'Gula pasir putih premium', 'buy_price' => 14000, 'sell_price' => 18000, 'stock' => 100, 'image_url' => 'https://images.unsplash.com/photo-1581622558663-b2e33377dfb2?w=300&h=300&fit=crop'],

            // Perawatan Tubuh
            ['category' => 'Perawatan Tubuh', 'barcode' => 'PRW-0001', 'title' => 'Sabun Lifebuoy 85g', 'description' => 'Sabun mandi antibakteri', 'buy_price' => 4000, 'sell_price' => 6500, 'stock' => 150, 'image_url' => 'https://images.unsplash.com/photo-1600857062241-98e5dba7f214?w=300&h=300&fit=crop'],
            ['category' => 'Perawatan Tubuh', 'barcode' => 'PRW-0002', 'title' => 'Shampoo Pantene 170ml', 'description' => 'Shampoo anti rontok', 'buy_price' => 22000, 'sell_price' => 32000, 'stock' => 60, 'image_url' => 'https://images.unsplash.com/photo-1631729371254-42c2892f0e6e?w=300&h=300&fit=crop'],
            ['category' => 'Perawatan Tubuh', 'barcode' => 'PRW-0003', 'title' => 'Pasta Gigi Pepsodent 190g', 'description' => 'Pasta gigi pencegah gigi berlubang', 'buy_price' => 12000, 'sell_price' => 18000, 'stock' => 100, 'image_url' => 'https://images.unsplash.com/photo-1620916566398-39f1143ab7be?w=300&h=300&fit=crop'],

            // Kebutuhan Rumah
            ['category' => 'Kebutuhan Rumah', 'barcode' => 'RMH-0001', 'title' => 'Tisu Paseo 250 Sheet', 'description' => 'Tisu wajah lembut dan kuat', 'buy_price' => 15000, 'sell_price' => 22000, 'stock' => 80, 'image_url' => 'https://images.unsplash.com/photo-1584556812952-905ffd0c611a?w=300&h=300&fit=crop'],
            ['category' => 'Kebutuhan Rumah', 'barcode' => 'RMH-0002', 'title' => 'Sabun Cuci Piring 800ml', 'description' => 'Sabun cuci piring anti lemak', 'buy_price' => 12000, 'sell_price' => 18000, 'stock' => 90, 'image_url' => 'https://images.unsplash.com/photo-1585441695325-21557ab93f7e?w=300&h=300&fit=crop'],
            ['category' => 'Kebutuhan Rumah', 'barcode' => 'RMH-0003', 'title' => 'Pewangi Pakaian 900ml', 'description' => 'Pelembut dan pewangi pakaian', 'buy_price' => 18000, 'sell_price' => 26000, 'stock' => 70, 'image_url' => 'https://images.unsplash.com/photo-1626806819282-2c1dc01a5e0c?w=300&h=300&fit=crop'],
        ]);

        return $products->map(function ($product) use ($categories) {
            $category = $categories->get($product['category']);

            // Download product image
            $slug  = Str::slug($product['title']);
            $image = $this->downloadImage(
                $product['image_url'],
                'products',
                'prod-' . $slug
            );

            return Product::create([
                'category_id' => $category?->id,
                'image'       => $image ?? 'default.jpg',
                'barcode'     => $product['barcode'],
                'title'       => $product['title'],
                'description' => $product['description'],
                'buy_price'   => $product['buy_price'],
                'sell_price'  => $product['sell_price'],
                'stock'       => $product['stock'],
            ]);
        })->keyBy('barcode');
    }

    /**
     * Seed historical transactions, transaction details, and profits.
     */
    private function seedTransactions(Collection $customers, Collection $products): void
    {
        $cashier = User::where('email', 'cashier@gmail.com')->first() ?? User::first();

        if (! $cashier) {
            return;
        }

        $blueprints = [
            [
                'customer' => 'Andi Nugraha',
                'discount' => 5000,
                'cash'     => 100000,
                'items'    => [
                    ['barcode' => 'MNM-0001', 'qty' => 3],
                    ['barcode' => 'SNK-0001', 'qty' => 2],
                    ['barcode' => 'RTI-0001', 'qty' => 1],
                ],
            ],
            [
                'customer' => 'Bunga Maharani',
                'discount' => 0,
                'cash'     => 150000,
                'items'    => [
                    ['barcode' => 'SSU-0001', 'qty' => 2],
                    ['barcode' => 'RTI-0002', 'qty' => 3],
                    ['barcode' => 'PRW-0001', 'qty' => 2],
                ],
            ],
            [
                'customer' => 'Cici Amelia',
                'discount' => 10000,
                'cash'     => 200000,
                'items'    => [
                    ['barcode' => 'MKN-0002', 'qty' => 2],
                    ['barcode' => 'BMB-0002', 'qty' => 1],
                    ['barcode' => 'RMH-0001', 'qty' => 2],
                ],
            ],
            [
                'customer' => 'Davin Pradipta',
                'discount' => 0,
                'cash'     => 80000,
                'items'    => [
                    ['barcode' => 'MNM-0003', 'qty' => 2],
                    ['barcode' => 'SNK-0003', 'qty' => 5],
                    ['barcode' => 'SSU-0002', 'qty' => 2],
                ],
            ],
            [
                'customer' => 'Fitri Lestari',
                'discount' => 15000,
                'cash'     => 250000,
                'items'    => [
                    ['barcode' => 'PRW-0002', 'qty' => 1],
                    ['barcode' => 'BMB-0001', 'qty' => 2],
                    ['barcode' => 'MKN-0003', 'qty' => 2],
                    ['barcode' => 'RMH-0003', 'qty' => 1],
                ],
            ],
            [
                'customer' => null,
                'discount' => 0,
                'cash'     => 50000,
                'items'    => [
                    ['barcode' => 'MNM-0002', 'qty' => 2],
                    ['barcode' => 'SNK-0002', 'qty' => 1],
                ],
            ],
        ];

        foreach ($blueprints as $blueprint) {
            $customer = $blueprint['customer']
                ? $customers->get($blueprint['customer'])
                : null;

            $items = collect($blueprint['items'])
                ->map(function ($item) use ($products) {
                    $product = $products->get($item['barcode']);

                    if (! $product) {
                        return null;
                    }

                    $lineTotal = $product->sell_price * $item['qty'];

                    return [
                        'product'    => $product,
                        'qty'        => $item['qty'],
                        'line_total' => $lineTotal,
                        'profit'     => ($product->sell_price - $product->buy_price) * $item['qty'],
                    ];
                })
                ->filter();

            if ($items->isEmpty()) {
                continue;
            }

            $discount   = max(0, $blueprint['discount']);
            $gross      = $items->sum('line_total');
            $grandTotal = max(0, $gross - $discount);
            $cashPaid   = max($grandTotal, $blueprint['cash']);
            $change     = $cashPaid - $grandTotal;

            $transaction = Transaction::create([
                'cashier_id'  => $cashier->id,
                'customer_id' => $customer?->id,
                'invoice'     => 'TRX-' . Str::upper(Str::random(8)),
                'cash'        => $cashPaid,
                'change'      => $change,
                'discount'    => $discount,
                'grand_total' => $grandTotal,
            ]);

            foreach ($items as $item) {
                $transaction->details()->create([
                    'product_id' => $item['product']->id,
                    'qty'        => $item['qty'],
                    'price'      => $item['line_total'],
                ]);

                $transaction->profits()->create([
                    'total' => $item['profit'],
                ]);

                $item['product']->decrement('stock', $item['qty']);
            }
        }
    }
}
