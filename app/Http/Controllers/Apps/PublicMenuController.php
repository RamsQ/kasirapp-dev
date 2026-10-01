<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Discount;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Table;
use App\Services\StockIntelligenceService;
use App\Services\TaxService;
use Illuminate\Http\Request; // Menambahkan Request untuk menangkap query takeaway
use Inertia\Inertia;

class PublicMenuController extends Controller
{
    public function __construct(
        protected TaxService $taxService,
        protected StockIntelligenceService $stockIntelligence,
    ) {}

    /**
     * Menampilkan menu publik untuk pelanggan.
     * Mendukung mode Dine-In (via table_id) dan Take-Away (via query string).
     */
    public function index(Request $request, $table_id = null)
    {
        // 1. CEK MODE PESANAN (Take Away vs Dine In)
        // Jika URL mengandung ?type=takeaway, maka isTakeAway = true
        $isTakeAway = $request->query('type') === 'takeaway';

        // 2. AMBIL SEMUA PROMO YANG AKTIF SAAT INI (Fitur Fix)
        $activePromos = Discount::with(['product', 'bonusProduct'])
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->get();

        // 3. AMBIL DATA PRODUK DENGAN LOGIKA KETERSEDIAAN STOK/RESEP (Fitur Fix)
        $products = Product::with(['recipes.ingredient', 'bundle_items.units', 'category'])
            ->where(function ($query) {
                // Produk muncul jika memiliki stok fisik, resep, atau bundle.
                $query->where('stock', '>', 0)
                    ->orWhereHas('recipes')
                    ->orWhereHas('bundle_items');
            })
            ->orderBy('title')
            ->get()
            ->map(function ($product) {
                $availability = $this->stockIntelligence->availabilityForProduct($product);

                $product->is_available = $availability['is_available'];
                $product->available_quantity = $availability['available_quantity'];
                $product->availability_status = $availability['status'];
                $product->availability_shortages = $availability['shortages'];

                return $product;
            });

        $taxSetting = Setting::first();
        $taxCalculation = $this->taxService->calculate(0, $taxSetting);

        // QRIS statis milik merchant. Pelanggan yang memilih QRIS akan
        // memindai gambar ini dari HP-nya sendiri, lalu kasir yang
        // memverifikasi pembayarannya. Kredensial gateway tidak pernah ikut
        // dikirim ke halaman publik.
        $setting = PaymentSetting::first();

        $qris = $setting?->qrisConfig() ?? ['enabled' => false, 'image_url' => null];

        // 4. RENDER KE FRONTEND (Public/CustomerMenu)
        return Inertia::render('Public/CustomerMenu', [
            'products' => $products,
            'activePromos' => $activePromos,
            'isTakeAway' => $isTakeAway, // Flag untuk memberitahu UI bahwa ini pesanan Bawa Pulang
            'table' => $table_id ? Table::find($table_id) : null,
            'categories' => Category::orderBy('name')->get(),
            'taxSetting' => [
                'enabled' => $taxCalculation['enabled'],
                'rate' => $taxCalculation['rate'],
            ],
            'qris' => [
                'enabled' => (bool) $qris['enabled'],
                'image_url' => $qris['enabled'] ? $qris['image_url'] : null,
            ],
            // Hanya nama dan label. Kredensial gateway TIDAK pernah ikut
            // dikirim ke halaman publik; halaman ini tidak pernah memanggil
            // provider secara langsung, semuanya lewat server.
            'paymentGateways' => $this->selfPayGateways($setting),
        ]);
    }

    /**
     * Gateway yang bisa dipakai pelanggan untuk membayar sendiri.
     *
     * Hanya yang benar-benar siap (kredensial terisi). Daftar ini yang
     * menentukan apakah tombol "Bayar Sendiri" muncul di halaman publik, jadi
     * jangan pernah memasukkan gateway yang belum terkonfigurasi: pelanggan
     * akan menekan tombol yang pasti gagal.
     *
     * @return array<int, array{name: string, label: string, enabled: bool}>
     */
    private function selfPayGateways(?PaymentSetting $setting): array
    {
        if (! $setting) {
            return [];
        }

        $gateways = [];

        if ($setting->isGatewayReady(PaymentSetting::GATEWAY_MIDTRANS)) {
            $gateways[] = [
                'name' => PaymentSetting::GATEWAY_MIDTRANS,
                'label' => 'QRIS Midtrans',
                'enabled' => true,
            ];
        }

        if ($setting->isGatewayReady(PaymentSetting::GATEWAY_XENDIT)) {
            $gateways[] = [
                'name' => PaymentSetting::GATEWAY_XENDIT,
                'label' => 'QRIS Xendit',
                'enabled' => true,
            ];
        }

        return $gateways;
    }
}
