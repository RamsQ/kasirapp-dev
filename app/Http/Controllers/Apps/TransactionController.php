<?php

namespace App\Http\Controllers\Apps;

use App\Events\OrderDeleted;
use App\Events\OrderPlaced;
use App\Events\TransactionCreated;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\StockUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\Hold;
use App\Models\OnlineSetting;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ReceiptSetting;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Table;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CogsService;
use App\Services\LoyaltyService;
use App\Services\Payments\HoldPaymentService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentSettlementService;
use App\Services\Payments\PaymentTimelineService;
use App\Services\StockIntelligenceService;
use App\Services\TaxService;
use App\Support\SafeEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TransactionController extends Controller
{
    protected $cogsService;

    public function __construct(
        CogsService $cogsService,
        protected TaxService $taxService,
        protected StockIntelligenceService $stockIntelligence,
        protected PaymentSettlementService $paymentSettlements,
        protected LoyaltyService $loyalty,
        protected HoldPaymentService $holdPayments,
    ) {
        $this->cogsService = $cogsService;
    }

    private function resolveBundlingRelasi($cartItems)
    {
        $processed = [];
        foreach ($cartItems as $item) {
            $product = Product::with(['bundle_items.units', 'units'])->find($item['product_id'] ?? null);
            if (! $product) {
                continue;
            }

            $item['product'] = $product;
            $item['notes'] = isset($item['notes']) && trim((string) $item['notes']) !== ''
                ? trim((string) $item['notes'])
                : null;
            $item['qty'] = max(0, (float) ($item['qty'] ?? 0));

            // Rebuild financial fields from master data. This prevents a
            // modified hold/cart payload from inventing a price or platform fee.
            if ($item['notes'] !== 'BONUS PROMO' && $item['qty'] > 0) {
                $pricing = $this->resolveOnlinePricing(
                    $product,
                    $item['product_unit_id'] ?? null,
                    $item['online_platform_id'] ?? null
                );
                $unitPrice = $this->getDiscountedUnitPrice(
                    $product->id,
                    $pricing['unit_price'],
                    $item['qty']
                );
                $item['price'] = $unitPrice * $item['qty'];
                $item['online_platform_id'] = $pricing['platform_id'];
                $item['platform_markup'] = $pricing['platform_markup'];
                $item['platform_fee'] = $pricing['platform_fee'];
            } else {
                $item['online_platform_id'] = null;
                $item['platform_markup'] = 0;
                $item['platform_fee'] = 0;
                $item['price'] = 0;
            }

            $processed[] = $item;
        }

        return $processed;
    }

    /**
     * Resolve the server-side online price and its cost components.
     * The client may choose a platform, but it cannot invent its fee values.
     *
     * @return array{base_price: float, unit_price: float, platform_id: int|null, platform_markup: float, platform_fee: float}
     */
    private function resolveOnlinePricing(Product $product, $unitId = null, $platformId = null): array
    {
        $basePrice = $unitId
            ? (float) (ProductUnit::find($unitId)?->sell_price ?? $product->sell_price)
            : (float) $product->sell_price;

        $platform = filled($platformId)
            ? OnlineSetting::where('id', $platformId)->where('is_active', true)->first()
            : null;

        $platformMarkup = $platform
            ? round($basePrice * max(0, (float) $platform->markup_percent) / 100, 2)
            : 0.0;
        $platformFee = $platform ? max(0, (float) $platform->additional_fee) : 0.0;

        return [
            'base_price' => $basePrice,
            'unit_price' => $basePrice + $platformMarkup + $platformFee,
            'platform_id' => $platform?->id,
            'platform_markup' => $platformMarkup,
            'platform_fee' => $platformFee,
        ];
    }

    private function getDiscountedUnitPrice($productId, $basePrice, $qty = 1)
    {
        $autoPromo = Discount::active()
            ->where('product_id', $productId)
            ->whereNull('bonus_product_id')
            ->where('minimum_item', '<=', $qty)
            ->where('minimum_item', '>', 0)
            ->orderBy('minimum_item', 'desc')
            ->first();

        if ($autoPromo) {
            if ($autoPromo->type === 'percentage') {
                return (float) ($basePrice - ($basePrice * ($autoPromo->value / 100)));
            }

            return (float) ($basePrice - $autoPromo->value);
        }

        return (float) $basePrice;
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        $userId = $user->id;
        $activeShift = Shift::where('user_id', $userId)->where('status', 'open')->first();

        $carts = Cart::with(['product.units', 'product.bundle_items.units', 'unit'])
            ->where('cashier_id', $userId)->active()->latest()->get();

        $cartsTotalAmount = (int) $carts->sum('price');
        $holds = Hold::with('table')->whereNull('transaction_id')->latest()->get();

        // Struk digital pelanggan, untuk setiap pesanan menu QR yang masih
        // menunggu. Kasir membukanya untuk memeriksa pembayaran atau untuk
        // menunjukkan ke pelanggan kalau layar pelanggan rusak.
        $holds->each(function (Hold $hold): void {
            $hold->setAttribute('receipt_url', URL::signedRoute('public.menu.receipt', [
                'ref' => $hold->ref_number,
            ]));
        });
        $tables = Table::orderBy('name')->get();

        $productsQuery = Product::with(['category:id,name', 'bundle_items', 'units', 'recipes'])
            ->select('id', 'barcode', 'title', 'description', 'image', 'buy_price', 'sell_price', 'stock', 'category_id', 'type', 'unit')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('type', '!=', 'bundle')
                        ->where(function ($sub) {
                            $sub->where('stock', '>', 0)->orHas('recipes');
                        });
                })
                    ->orWhere(function ($q) {
                        $q->where('type', 'bundle')
                            ->whereHas('bundle_items', function ($sub) {
                                $sub->where('stock', '>', 0);
                            });
                    });
            })
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', '%'.$search.'%')
                        ->orWhere('barcode', 'like', '%'.$search.'%');
                });
            })
            ->when($request->category_id, function ($query, $catId) {
                if ($catId !== 'all' && $catId) {
                    $query->where('category_id', $catId);
                }
            })
            ->orderBy('title');

        if ($request->wantsJson()) {
            return response()->json($productsQuery->paginate(15)->withQueryString());
        }

        $taxSetting = Setting::first();
        $taxCalculation = $this->taxService->calculate(0, $taxSetting);
        $paymentSetting = PaymentSetting::first();
        if ($paymentSetting) {
            $paymentSetting->setAttribute('midtrans_ready', $paymentSetting->isGatewayReady(PaymentSetting::GATEWAY_MIDTRANS));
            $paymentSetting->setAttribute('xendit_ready', $paymentSetting->isGatewayReady(PaymentSetting::GATEWAY_XENDIT));
            $paymentSetting->makeHidden([
                'midtrans_server_key',
                'xendit_secret_key',
                'xendit_callback_token',
            ]);
        } else {
            // Tanpa baris payment_settings, halaman kasir tetap harus renders.
            // Mengirim null membuat frontend crash saat membaca properti.
            // forceFill dipakai karena flag di bawah bukan bagian $fillable.
            $paymentSetting = (new PaymentSetting)->forceFill([
                'midtrans_production' => false,
                'midtrans_ready' => false,
                'xendit_ready' => false,
            ]);
        }

        return Inertia::render('Dashboard/Transactions/Index', [
            'carts' => $carts,
            'carts_total' => $cartsTotalAmount,
            'holds' => $holds,
            'tables' => $tables,
            'customers' => Customer::select('id', 'name', 'no_telp', 'phone', 'loyalty_points', 'loyalty_tier')->latest()->get(),
            'products' => $productsQuery->paginate(15)->withQueryString(),
            'categories' => Category::select('id', 'name', 'image')->orderBy('name')->get(),
            'discounts' => Discount::active()->with(['product:id,title', 'bonusProduct:id,title'])->get(),
            'paymentSetting' => $paymentSetting,
            'activeShift' => $activeShift,
            // Sisa kas dari shift yang terakhir ditutup, dipakai sebagai
            // float shift berikutnya.
            //
            // Float laci berputar, bukan tambahan baru. Kalau kasir mengisi
            // ulang nominal yang sama setiap hari, uang yang tertinggal di
            // laci akan terhitung dua kali di neraca sementara Modal Disetor
            // cuma tercatat sekali, dan selisih neraca naik terus.
            'lastClosedShift' => Shift::where('user_id', $userId)
                ->where('status', 'closed')
                ->whereNotNull('total_cash_actual')
                ->orderByDesc('closed_at')
                ->orderByDesc('id')
                ->first(['closed_at', 'total_cash_actual', 'total_cash_expected', 'difference']),
            'taxSetting' => [
                'enabled' => $taxCalculation['enabled'],
                'rate' => $taxCalculation['rate'],
            ],
            'receiptSetting' => ReceiptSetting::first(),
            'onlineSettings' => OnlineSetting::where('is_active', true)->get(),
            'filters' => $request->all(['search', 'category_id']),
        ]);
    }

    public function addToCart(Request $request)
    {
        $userId = auth()->id();
        $product = Product::findOrFail($request->product_id);
        $unitId = $request->product_unit_id ?? null;

        try {
            DB::transaction(function () use ($userId, $product, $unitId, $request): void {
                $platformId = $request->input('online_platform_id');
                $pricing = $this->resolveOnlinePricing($product, $unitId, $platformId);
                $cart = Cart::where([
                    'product_id' => $product->id,
                    'product_unit_id' => $unitId,
                    'cashier_id' => $userId,
                    'online_platform_id' => $pricing['platform_id'],
                    'notes' => $request->notes ?? null,
                ])->first();

                $newQty = $cart ? $cart->qty + 1 : 1;
                $unitPrice = $this->getDiscountedUnitPrice($product->id, $pricing['unit_price'], $newQty);

                if ($cart) {
                    $cart->update([
                        'qty' => $newQty,
                        'price' => $unitPrice * $newQty,
                        'platform_markup' => $pricing['platform_markup'],
                        'platform_fee' => $pricing['platform_fee'],
                    ]);
                } else {
                    Cart::create([
                        'cashier_id' => $userId,
                        'product_id' => $product->id,
                        'product_unit_id' => $unitId,
                        'online_platform_id' => $pricing['platform_id'],
                        'qty' => 1,
                        'price' => $unitPrice,
                        'platform_markup' => $pricing['platform_markup'],
                        'platform_fee' => $pricing['platform_fee'],
                        'notes' => $request->notes ?? null,
                    ]);
                }

                $bonusPromo = Discount::active()
                    ->where('product_id', $product->id)
                    ->whereNotNull('bonus_product_id')
                    ->first();
                if ($bonusPromo) {
                    $minBuy = (int) $bonusPromo->minimum_item;
                    if ($minBuy > 0) {
                        $expectedBonusQty = (int) floor($newQty / $minBuy);
                        Cart::where([
                            'product_id' => $bonusPromo->bonus_product_id,
                            'cashier_id' => $userId,
                            'notes' => 'BONUS PROMO',
                        ])->delete();
                        if ($expectedBonusQty > 0) {
                            Cart::create([
                                'cashier_id' => $userId,
                                'product_id' => $bonusPromo->bonus_product_id,
                                'qty' => $expectedBonusQty,
                                'price' => 0,
                                'notes' => 'BONUS PROMO',
                            ]);
                        }
                    }
                }

                $this->stockIntelligence->assertAvailableForItems(
                    Cart::where('cashier_id', $userId)->active()->get()
                );
            });
        } catch (StockUnavailableException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function updateCart(Request $request, $cart_id)
    {
        $request->validate([
            'qty' => ['required', 'numeric', 'min:0'],
            'product_unit_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);
        $userId = auth()->id();
        $cart = Cart::with('product')
            ->where('cashier_id', $userId)
            ->whereKey($cart_id)
            ->firstOrFail();
        if ($cart->notes === 'BONUS PROMO') {
            return back();
        }

        try {
            DB::transaction(function () use ($request, $cart, $userId): void {
                $unitId = $request->product_unit_id ?? $cart->product_unit_id;
                $pricing = $this->resolveOnlinePricing($cart->product, $unitId, $cart->online_platform_id);
                $unitPrice = $this->getDiscountedUnitPrice(
                    $cart->product_id,
                    $pricing['unit_price'],
                    $request->qty
                );

                $cart->update([
                    'qty' => $request->qty,
                    'product_unit_id' => $unitId,
                    'online_platform_id' => $pricing['platform_id'],
                    'price' => $unitPrice * $request->qty,
                    'platform_markup' => $pricing['platform_markup'],
                    'platform_fee' => $pricing['platform_fee'],
                    'notes' => $request->notes,
                ]);

                $bonusPromo = Discount::active()
                    ->where('product_id', $cart->product_id)
                    ->whereNotNull('bonus_product_id')
                    ->first();
                if ($bonusPromo) {
                    $minBuy = (int) $bonusPromo->minimum_item;
                    if ($minBuy > 0) {
                        $expectedBonus = (int) floor($request->qty / $minBuy);
                        Cart::where([
                            'product_id' => $bonusPromo->bonus_product_id,
                            'cashier_id' => $userId,
                            'notes' => 'BONUS PROMO',
                        ])->delete();
                        if ($expectedBonus > 0) {
                            Cart::create([
                                'cashier_id' => $userId,
                                'product_id' => $bonusPromo->bonus_product_id,
                                'qty' => $expectedBonus,
                                'price' => 0,
                                'notes' => 'BONUS PROMO',
                            ]);
                        }
                    }
                }

                $this->stockIntelligence->assertAvailableForItems(
                    Cart::where('cashier_id', $userId)->active()->get()
                );
            });
        } catch (StockUnavailableException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function destroyCart($cart_id)
    {
        $userId = auth()->id();
        $cart = Cart::findOrFail($cart_id);
        $productId = $cart->product_id;
        $discount = Discount::active()->where('product_id', $productId)->first();
        if ($discount && $discount->bonus_product_id) {
            Cart::where(['product_id' => $discount->bonus_product_id, 'cashier_id' => $userId, 'notes' => 'BONUS PROMO'])->delete();
        }
        $cart->delete();

        return back();
    }

    public function store(Request $request, PaymentGatewayManager $paymentGatewayManager)
    {
        $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'voucher_code' => ['nullable', 'string', 'max:50'],
        ]);

        $activeShift = Shift::where('user_id', auth()->id())->where('status', 'open')->first();
        if (! $activeShift) {
            return response()->json(['message' => 'Shift belum dibuka!'], 403);
        }

        $paymentSetting = PaymentSetting::first();
        $method = strtolower($request->payment_gateway ?? 'cash');

        // Pesanan menu QR yang sudah dibayar pelanggan sendiri (QRIS Midtrans /
        // Xendit) TIDAK boleh ditagih lagi. Kalau kasir memilih gateway di
        // sini, sistem akan membuat QR kedua dan pelanggan bisa membayar dua
        // kali untuk satu pesanan.
        //
        // Jadi metode dan statusnya dikunci ke pembayaran yang sudah diterima,
        // dan penagihan gateway dilewati seluruhnya.
        $upfrontHold = $request->hold_id ? Hold::find((int) $request->hold_id) : null;
        $paidUpfront = $upfrontHold?->payment_status === 'paid';

        $isGateway = ! $paidUpfront
            && in_array($method, [PaymentSetting::GATEWAY_MIDTRANS, PaymentSetting::GATEWAY_XENDIT]);
        $paymentStatus = ($paidUpfront || ! $isGateway) ? 'paid' : 'pending';

        if ($paidUpfront && filled($upfrontHold->payment_method)) {
            // Samakan method dengan yang benar-benar dipakai pelanggan supaya
            // Kas Bank di neraca menerima uangnya di kolom yang tepat.
            $method = strtolower((string) $upfrontHold->payment_method);
        }

        $cash = (float) str_replace(',', '', $request->cash ?? 0);
        $change = (float) str_replace(',', '', $request->change ?? 0);
        $setting = Setting::first() ?? Setting::create([
            'cogs_method' => 'AVERAGE',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => true,
            'loyalty_points_per_100' => 1,
            'kitchen_display_enabled' => false,
            'kitchen_display_payment_status' => 'both',
        ]);
        $cogsMethod = $setting->cogs_method ?? 'AVERAGE';
        $taxSetting = $setting;
        $invoice = 'TRX-'.Str::upper(Str::random(10));

        try {
            $transaction = DB::transaction(function () use ($request, $invoice, $activeShift, $cogsMethod, $taxSetting, $cash, $paymentStatus, $method, $isGateway, $paidUpfront, $upfrontHold) {

                $cartItems = Cart::with(['product.bundle_items', 'product.units', 'product.recipes'])->where('cashier_id', auth()->id())->get();
                if ($cartItems->isEmpty()) {
                    // Ini kesalahan input kasir, bukan kegagalan server.
                    throw ValidationException::withMessages(['message' => 'Keranjang kosong.']);
                }

                // Harga dan komisi platform dihitung ULANG di sini, bukan
                // diambil dari snapshot saat item dimasukkan keranjang.
                // Kalau tidak, platform yang sudah dimatikan pemilik tetap
                // memotong komisi dan pelanggan tetap membayar harga lama.
                // Diskon, pajak, dan HPP sudah dihitung ulang saat checkout
                // sejak awal; harga harus konsisten perlakuannya.
                $cartItems->each(function (Cart $cart): void {
                    if ((float) $cart->qty <= 0 || $cart->notes === 'BONUS PROMO') {
                        return;
                    }

                    $pricing = $this->resolveOnlinePricing(
                        $cart->product,
                        $cart->product_unit_id,
                        $cart->online_platform_id
                    );

                    $unitPrice = $this->getDiscountedUnitPrice(
                        $cart->product_id,
                        $pricing['unit_price'],
                        (float) $cart->qty
                    );

                    $cart->platform_markup = $pricing['platform_markup'];
                    $cart->platform_fee = $pricing['platform_fee'];
                    $cart->price = $unitPrice * (float) $cart->qty;
                });

                $totalMarkup = (float) $cartItems->sum(
                    fn (Cart $cart): float => (float) $cart->platform_markup * (float) $cart->qty
                );
                $totalFee = (float) $cartItems->sum(
                    fn (Cart $cart): float => (float) $cart->platform_fee * (float) $cart->qty
                );
                $platformIds = $cartItems->pluck('online_platform_id')->filter()->unique()->values();
                $onlinePlatformName = $platformIds->count() === 1
                    ? OnlineSetting::whereKey($platformIds->first())->value('name')
                    : ($platformIds->count() > 1 ? 'MULTI' : null);

                $hold = $request->hold_id ? Hold::find($request->hold_id) : null;
                if ($request->hold_id && ! $hold) {
                    throw ValidationException::withMessages([
                        'message' => 'Pesanan ditangguhkan tidak valid atau sudah diproses.',
                    ]);
                }
                $cartPayload = $cartItems->map(fn (Cart $cart): array => $cart->toArray())->all();
                if ($isGateway) {
                    $this->stockIntelligence->assertAvailableForItems($cartPayload, $hold?->id);
                } else {
                    $this->stockIntelligence->consumeForCheckout($cartPayload, $hold?->id);
                }
                $finalReferenceCode = $hold ? $hold->reference_code : ($request->reference_code ?? Str::random(4));

                $finalQueueNumber = $request->queue_number;
                if ($hold && $hold->queue_number) {
                    $finalQueueNumber = $hold->queue_number;
                }

                if (! $finalQueueNumber) {
                    $todayCount = Transaction::whereDate('created_at', now())->count() + Hold::whereDate('created_at', now())->count();
                    $finalQueueNumber = 'Q-'.str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);
                }

                $subtotalKeranjang = (float) $cartItems->sum('price');
                $diskonOtomatisGlobal = 0;
                $promoGlobal = Discount::active()
                    ->whereNull('product_id')
                    ->where('min_transaction', '<=', $subtotalKeranjang)
                    ->orderBy('min_transaction', 'desc')
                    ->first();

                if ($promoGlobal) {
                    $discountValue = ($promoGlobal->type === 'percentage')
                        ? ($subtotalKeranjang * ($promoGlobal->value / 100))
                        : (float) $promoGlobal->value;
                    $diskonOtomatisGlobal = (int) round(
                        min($subtotalKeranjang, max(0, $discountValue)),
                        0,
                        PHP_ROUND_HALF_UP
                    );
                }

                $customer = $request->customer_id ? Customer::findOrFail($request->customer_id) : null;
                $voucherCode = trim((string) ($request->voucher_code ?? ''));
                $voucherDiscount = 0;
                if ($voucherCode !== '') {
                    if (! $customer) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'voucher_code' => 'Pilih pelanggan sebelum menggunakan voucher.',
                        ]);
                    }
                    $voucherDiscount = $this->loyalty->voucherDiscountFor(
                        $customer,
                        $voucherCode,
                        max(0, (int) round($subtotalKeranjang - $diskonOtomatisGlobal)),
                    );
                }
                $totalDiscount = $diskonOtomatisGlobal + $voucherDiscount;
                $taxableTotal = max(0, $subtotalKeranjang - $totalDiscount);
                $tax = $this->taxService->calculate($taxableTotal, $taxSetting);
                $grandTotalFinal = $tax['total'];
                $kitchenStatus = $taxSetting?->kitchen_display_enabled ? 'pending' : 'completed';
                $calculatedChange = $method === 'cash' ? max(0, $cash - $grandTotalFinal) : 0;

                if ($method === 'cash' && $cash < $grandTotalFinal) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'message' => 'Jumlah uang tunai tidak mencukupi untuk total transaksi.',
                    ]);
                }

                $transaction = Transaction::create([
                    'cashier_id' => auth()->id(),
                    'customer_id' => $request->customer_id,
                    'shift_id' => $activeShift->id,
                    'invoice' => $invoice,
                    'reference_code' => $finalReferenceCode,
                    // Disalin dari Hold supaya URL struk digital pelanggan
                    // tetap hidup setelah Hold dihapus saat penagihan. Tanpa
                    // ini, bukti yang dibawa pelanggan ke kasir jadi 404 tepat
                    // setelah pesanan diproses.
                    'menu_ref' => $hold?->ref_number,
                    'customer_name' => $request->customer_name ?? 'Pelanggan',
                    'cash' => $cash,
                    'change' => $calculatedChange,
                    'discount' => $totalDiscount,
                    'voucher_code' => $voucherCode ?: null,
                    'voucher_discount' => $voucherDiscount,
                    'taxable_amount' => $tax['taxable'],
                    'tax_rate' => $tax['enabled'] ? $tax['rate'] : null,
                    'tax_amount' => $tax['amount'],
                    'grand_total' => $grandTotalFinal,
                    'payment_method' => $method,
                    'payment_status' => $paymentStatus,
                    'kitchen_status' => $kitchenStatus,
                    'table_name' => $request->table_name ?: 'BAWA PULANG',
                    'queue_number' => $finalQueueNumber,
                    'online_platform' => $onlinePlatformName,
                    'total_markup' => $totalMarkup,
                    'total_fee' => $totalFee,
                    // Pembayaran mandiri dari menu QR sudah masuk SEBELUM
                    // transaksi ini dibuat, jadi waktu lunasnya ikut
                    // dicatat. Tanpa ini, laporan menampilkan penjualan lunas
                    // tanpa tanggal pembayaran, dan rekonsiliasi Kas Bank
                    // tidak akan pernah cocok dengan mutasi rekening.
                    'paid_at' => $paidUpfront ? ($upfrontHold->paid_at ?: now()) : null,
                    'settled_at' => $paidUpfront ? ($upfrontHold->paid_at ?: now()) : null,
                ]);

                if ($voucherCode !== '') {
                    $this->loyalty->reserveVoucher(
                        $customer,
                        $voucherCode,
                        max(0, (int) round($subtotalKeranjang - $diskonOtomatisGlobal)),
                        $transaction,
                    );
                }

                foreach ($cartItems as $cart) {
                    $product = $cart->product;
                    $totalItemSellingPrice = (float) $cart->price;

                    $totalItemCost = 0;
                    if ($product->type === 'bundle') {
                        foreach ($product->bundle_items as $item) {
                            $bundleConversion = $item->pivot->product_unit_id ? (ProductUnit::find($item->pivot->product_unit_id)->conversion ?? 1) : 1;
                            $totalItemCost += $this->cogsService->calculate($item->id, $cart->qty * $item->pivot->qty * $bundleConversion, $cogsMethod, null, $transaction, $isGateway);
                        }
                    } else {
                        $conversionValue = $cart->product_unit_id ? (ProductUnit::find($cart->product_unit_id)->conversion ?? 1) : 1;
                        $totalItemCost = $this->cogsService->calculate($cart->product_id, $cart->qty * $conversionValue, $cogsMethod, null, $transaction, $isGateway);
                    }

                    $itemProportion = $subtotalKeranjang > 0 ? ($totalItemSellingPrice / $subtotalKeranjang) : 0;
                    $itemDiscountAllocation = $totalDiscount * $itemProportion;
                    $appCommissionJatah = ($totalMarkup + $totalFee) * $itemProportion;

                    $realNetProfit = $totalItemSellingPrice - $itemDiscountAllocation - $appCommissionJatah - $totalItemCost;

                    $transaction->details()->create([
                        'product_id' => $cart->product_id,
                        'qty' => $cart->qty,
                        'price' => $totalItemSellingPrice,
                        'buy_price' => $cart->qty > 0 ? ($totalItemCost / $cart->qty) : 0,
                        'unit' => $cart->unit->unit_name ?? $product->unit ?? 'Pcs',
                        'product_unit_id' => $cart->product_unit_id,
                        'notes' => $cart->notes,
                    ]);

                    $transaction->profits()->create(['total' => (float) $realNetProfit]);
                }

                if (! $isGateway) {
                    if ($voucherCode !== '') {
                        $this->loyalty->consumeVoucher($transaction);
                    }
                    try {
                        $this->loyalty->recordPaidTransaction($transaction);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }

                if ($request->hold_id) {
                    $hold = Hold::find($request->hold_id);
                    if ($hold) {
                        if ($isGateway) {
                            // Hold dan reservasi tetap aktif sampai callback gateway.
                            $hold->update(['transaction_id' => $transaction->id]);
                        } else {
                            // Jejak pembayaran pindah ke transaksi resmi DULU
                            // hold dihapus. Kalau tidak, transaksi yang dibayar
                            // sendiri lewat QRIS akan punya riwayat pembayaran
                            // kosong — padahal justru itu yang paling perlu
                            // ditelusuri waktu ada selisih uang.
                            $this->holdPayments->attachEventsToTransaction($hold, $transaction);

                            $this->stockIntelligence->releaseForHold($hold);
                            if ($hold->table_id) {
                                Table::where('id', $hold->table_id)->update(['status' => 'available']);
                            }
                            $hold->delete();
                            $staffs = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'cashier']))->get();
                            foreach ($staffs as $staff) {
                                SafeEvent::dispatch(new OrderDeleted($staff->id), 'order.deleted');
                            }
                        }
                    }
                }

                // Keranjang dihapus HANYA jika bukan Gateway.
                // Jika Gateway, keranjang tetap ada untuk jaga-jaga jika modal ditutup (X)
                if (! $isGateway) {
                    Cart::where('cashier_id', auth()->id())->delete();
                }

                return $transaction;
            });

            if ($isGateway) {
                $this->paymentSettlements->begin($transaction, $method);

                try {
                    $paymentResponse = $paymentGatewayManager->createPayment($transaction, $method, $paymentSetting);
                    $this->paymentSettlements->attachProviderReference(
                        transaction: $transaction,
                        provider: $method,
                        reference: $paymentResponse['reference'] ?? $paymentResponse['token'] ?? null,
                        paymentUrl: $paymentResponse['payment_url'] ?? null,
                        metadata: [
                            'payment_url_created' => filled($paymentResponse['payment_url'] ?? null),
                        ],
                    );
                    $gatewayResponse = [
                        'success' => true,
                        'payment_url' => $paymentResponse['payment_url'] ?? null,
                        'token' => $paymentResponse['token'] ?? null,
                        'invoice' => $transaction->invoice,
                        'grand_total' => $transaction->grand_total,
                        'taxable_amount' => $transaction->taxable_amount,
                        'tax_rate' => $transaction->tax_rate,
                        'tax_amount' => $transaction->tax_amount,
                    ];

                    return $request->expectsJson()
                        ? response()->json($gatewayResponse)
                        : redirect()->route('transactions.print', $transaction->invoice);
                } catch (PaymentGatewayException $e) {
                    $this->paymentSettlements->markGatewayFailure($transaction, $method, $e->getMessage());

                    return response()->json(['message' => 'Gagal koneksi ke Gateway: '.$e->getMessage()], 500);
                } catch (\Throwable $e) {
                    $this->paymentSettlements->markGatewayFailure($transaction, $method, $e->getMessage());

                    return response()->json(['message' => 'Gagal membuat pembayaran gateway.'], 500);
                }
            }

            // Trigger Event Streaming ke Kafka dengan memuat ulang data model segar beserta relasinya
            event(new TransactionCreated($transaction->fresh(['details.product', 'customer'])));

            $checkoutResponse = [
                'success' => true,
                'invoice' => $transaction->invoice,
                'grand_total' => $transaction->grand_total,
                'taxable_amount' => $transaction->taxable_amount,
                'tax_rate' => $transaction->tax_rate,
                'tax_amount' => $transaction->tax_amount,
                'message' => 'Transaksi Berhasil',
            ];

            return $request->expectsJson()
                ? response()->json($checkoutResponse)
                : redirect()->route('transactions.print', $transaction->invoice);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['voucher_code' => [$e->getMessage()]],
            ], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Transaksi Gagal: '.$e->getMessage()], 500);
        }
    }

    /**
     * FIX: Fungsi untuk membersihkan transaksi jika Kasir klik (X) pada Pop-up Midtrans
     */
    public function cancelGateway(Request $request)
    {
        // Cari transaksi yang masih PENDING berdasarkan invoice
        $transaction = Transaction::where('invoice', $request->invoice)
            ->where('payment_status', 'pending')
            ->first();

        if ($transaction) {
            try {
                $this->paymentSettlements->cancelPending(
                    $transaction,
                    'Cancelled by cashier after payment window closed',
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Pembayaran ditandai batal; callback settlement tetap dapat diproses.',
                ]);
            } catch (\Throwable $exception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pembayaran tidak dapat dibatalkan: '.$exception->getMessage(),
                ], 422);
            }
        }

        return response()->json(['success' => false, 'message' => 'Data tidak ditemukan atau sudah dibayar.'], 404);
    }

    public function holdCart(Request $request)
    {
        $request->validate([
            'cart_items' => ['required', 'array'],
            'cart_items.*.id' => ['required', 'integer'],
            'cart_items.*.qty' => ['required', 'numeric', 'min:0'],
            'cart_items.*.notes' => ['nullable', 'string', 'max:200'],
            'total' => ['required', 'numeric', 'min:0'],
        ]);
        try {
            DB::transaction(function () use ($request) {
                $serverCarts = Cart::where('cashier_id', auth()->id())
                    ->active()
                    ->get()
                    ->keyBy('id');
                $trustedCartItems = collect($request->cart_items)
                    ->map(function ($item) use ($serverCarts) {
                        $cartId = $item['id'] ?? null;

                        return $cartId ? $serverCarts->get($cartId)?->toArray() : null;
                    })
                    ->filter()
                    ->values()
                    ->all();

                if ($trustedCartItems === []) {
                    throw new \Exception('Keranjang tidak valid atau sudah berubah.');
                }

                $processedCart = $this->resolveBundlingRelasi($trustedCartItems);
                $cleanSubtotal = (float) collect($processedCart)->sum('price');

                $diskonGlobal = 0;
                $promo = Discount::active()->whereNull('product_id')->where('min_transaction', '<=', $cleanSubtotal)->orderBy('min_transaction', 'desc')->first();
                if ($promo) {
                    $discountValue = ($promo->type === 'percentage')
                        ? $cleanSubtotal * ($promo->value / 100)
                        : (float) $promo->value;
                    $diskonGlobal = (int) round(
                        min($cleanSubtotal, max(0, $discountValue)),
                        0,
                        PHP_ROUND_HALF_UP
                    );
                }

                $taxableTotal = max(0, $cleanSubtotal - $diskonGlobal);
                $taxSetting = Setting::first();
                $tax = $this->taxService->calculate($taxableTotal, $taxSetting);
                $finalTotal = $tax['total'];
                $kitchenStatus = $taxSetting?->kitchen_display_enabled ? 'pending' : 'completed';
                $todayCount = Transaction::whereDate('created_at', now())->count() + Hold::whereDate('created_at', now())->count();
                $newQueueNumber = 'Q-'.str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);

                $holdRecord = $request->hold_id ? Hold::find($request->hold_id) : null;
                $uniqueCode = $holdRecord ? $holdRecord->reference_code : (string) rand(1000, 9999);

                $tableId = ($request->table_id === 'take_away' || ! $request->table_id) ? null : $request->table_id;

                if ($request->hold_id) {
                    $hold = Hold::findOrFail($request->hold_id);
                    if ($hold->transaction_id) {
                        throw new StockUnavailableException('Pesanan sudah diproses ke pembayaran gateway.');
                    }
                    if ($hold->table_id && $hold->table_id != $request->table_id) {
                        Table::where('id', $hold->table_id)->update(['status' => 'available']);
                    }
                    $hold->update([
                        'cart_data' => $processedCart,
                        'total' => $finalTotal,
                        'kitchen_status' => $kitchenStatus,
                        'taxable_amount' => $tax['taxable'],
                        'tax_rate' => $tax['enabled'] ? $tax['rate'] : null,
                        'tax_amount' => $tax['amount'],
                        'table_id' => $tableId,
                        'customer_name' => $request->customer_name,
                    ]);
                } else {
                    $hold = Hold::create([
                        'ref_number' => 'ORD-'.$uniqueCode,
                        'reference_code' => $uniqueCode,
                        'queue_number' => $newQueueNumber,
                        'customer_name' => $request->customer_name,
                        'table_id' => $tableId,
                        'cart_data' => $processedCart,
                        'total' => $finalTotal,
                        'kitchen_status' => $kitchenStatus,
                        'taxable_amount' => $tax['taxable'],
                        'tax_rate' => $tax['enabled'] ? $tax['rate'] : null,
                        'tax_amount' => $tax['amount'],
                        'user_id' => auth()->id(),
                    ]);
                }

                $this->stockIntelligence->reserveForHold($hold, $processedCart);

                if ($tableId) {
                    Table::where('id', $tableId)->update(['status' => 'occupied']);
                }
                Cart::where('cashier_id', auth()->id())->delete();

                $staffs = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'cashier']))->get();
                foreach ($staffs as $staff) {
                    SafeEvent::dispatch(new OrderPlaced($staff->id), 'order.placed');
                }
            });

            return back()->with('success', 'Pesanan ditangguhkan.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function customerOrder(Request $request)
    {
        $request->validate([
            'cart_items' => ['required', 'array', 'min:1'],
            'cart_items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'cart_items.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'cart_items.*.qty' => ['required', 'numeric', 'gt:0'],
            'cart_items.*.notes' => ['nullable', 'string', 'max:200'],
            'customer_name' => ['required', 'string', 'max:255'],
            'total' => ['required', 'numeric', 'min:0'],
            // Minat pelanggan, bukan catatan pembayaran. Pembayaran tetap
            // dicatat di kasir, dan transactions.payment_method yang jadi
            // otoritatif. Dibatasi supaya tidak ada nilai bebas yang nanti
            // tidak dikenal saat kasir memproses pesanan.
            'requested_payment_method' => ['nullable', 'string', Rule::in(Hold::PAYMENT_PREFERENCES)],
        ]);
        try {
            return DB::transaction(function () use ($request) {
                $targetUser = User::whereHas('roles', fn ($query) => $query->where('name', 'super-admin'))->first()
                    ?? User::whereHas('roles', fn ($query) => $query->where('name', 'cashier'))->first()
                    ?? User::query()->first();
                $targetUserId = $targetUser?->id;

                if (! $targetUserId) {
                    throw new \RuntimeException('Belum ada pengguna kasir yang dapat menerima pesanan.');
                }

                $cartDataWithBundling = $this->resolveBundlingRelasi($request->cart_items);
                $processedCart = [];
                $cleanSubtotal = 0;

                foreach ($cartDataWithBundling as $item) {
                    $product = Product::find($item['product_id']);
                    if (! $product) {
                        continue;
                    }

                    $unitPrice = $this->getDiscountedUnitPrice($product->id, $product->sell_price, $item['qty']);

                    $itemTotal = $unitPrice * $item['qty'];
                    $cleanSubtotal += $itemTotal;

                    $processedCart[] = array_merge($item, [
                        'price' => $itemTotal,
                        'unit_price' => $unitPrice,
                        'sell_price' => $product->sell_price,
                        'online_platform_id' => null,
                        'platform_markup' => 0,
                        'platform_fee' => 0,
                    ]);
                }

                $diskonGlobal = 0;
                $promo = Discount::active()->whereNull('product_id')->where('min_transaction', '<=', $cleanSubtotal)->orderBy('min_transaction', 'desc')->first();
                if ($promo) {
                    $discountValue = ($promo->type === 'percentage')
                        ? $cleanSubtotal * ($promo->value / 100)
                        : (float) $promo->value;
                    $diskonGlobal = (int) round(
                        min($cleanSubtotal, max(0, $discountValue)),
                        0,
                        PHP_ROUND_HALF_UP
                    );
                }
                $taxableTotal = max(0, $cleanSubtotal - $diskonGlobal);
                $taxSetting = Setting::first();
                $tax = $this->taxService->calculate($taxableTotal, $taxSetting);
                $finalTotal = $tax['total'];
                $kitchenStatus = $taxSetting?->kitchen_display_enabled ? 'pending' : 'completed';

                $tableId = ($request->order_type === 'takeaway' || ! $request->table_id) ? null : $request->table_id;
                $todayCount = Transaction::whereDate('created_at', now())->count() + Hold::whereDate('created_at', now())->count();
                $newQueueNumber = 'Q-'.str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);
                $uniqueCode = (string) rand(1000, 9999);
                $finalNameWithCode = trim($request->customer_name).' #'.$uniqueCode;

                // Minat pelanggan, bukan pembayaran. Kalau tidak diisi, kasir
                // tidak tahu harus menyiapkan QRIS atau uang tunai.
                $requestedPayment = $request->requested_payment_method ?: Hold::PREFERENCE_CASH;

                $existingHold = $tableId
                    ? Hold::where('table_id', $tableId)->whereNull('transaction_id')->first()
                    : null;

                // Begitu QR sudah ditagihkan, nominal yang dibayar pelanggan
                // terkunci di gateway. Menambah item sekarang berarti
                // pelanggan membayar kurang dari yang dia pesan, dan kalau
                // dia bayar ulang ada kemungkinan uang masuk dua kali.
                if ($existingHold && in_array($existingHold->payment_status, ['pending', 'paid'], true)) {
                    return response()->json([
                        'success' => false,
                        'message' => $existingHold->payment_status === 'paid'
                            ? 'Pesanan di meja ini sudah dibayar. Silakan hubungi kasir untuk menambah pesanan.'
                            : 'Pesanan di meja ini sedang menunggu pembayaran. Tunggu sebentar sebelum menambah pesanan.',
                    ], 409);
                }

                if ($existingHold) {
                    $hold = $existingHold;
                    $existingTaxableTotal = $existingHold->taxable_amount !== null
                        ? (float) $existingHold->taxable_amount
                        : max(0, (float) $existingHold->total - (float) $existingHold->tax_amount);
                    $combinedTax = $this->taxService->calculate($existingTaxableTotal + $taxableTotal, $taxSetting);

                    $hold->update([
                        'cart_data' => array_merge($existingHold->cart_data, $processedCart),
                        'total' => $combinedTax['total'],
                        'kitchen_status' => $kitchenStatus,
                        'taxable_amount' => $combinedTax['taxable'],
                        'tax_rate' => $combinedTax['enabled'] ? $combinedTax['rate'] : null,
                        'tax_amount' => $combinedTax['amount'],
                        'queue_number' => $newQueueNumber,
                        'customer_name' => $finalNameWithCode,
                        'requested_payment_method' => $requestedPayment,
                    ]);
                } else {
                    $hold = Hold::create([
                        'ref_number' => 'QR-'.$uniqueCode,
                        'reference_code' => $uniqueCode,
                        'queue_number' => $newQueueNumber,
                        'customer_name' => $finalNameWithCode,
                        'requested_payment_method' => $requestedPayment,
                        'table_id' => $tableId,
                        'cart_data' => $processedCart,
                        'total' => $finalTotal,
                        'kitchen_status' => $kitchenStatus,
                        'taxable_amount' => $tax['taxable'],
                        'tax_rate' => $tax['enabled'] ? $tax['rate'] : null,
                        'tax_amount' => $tax['amount'],
                        'user_id' => $targetUserId,
                    ]);
                    if ($tableId) {
                        Table::where('id', $tableId)->update(['status' => 'occupied']);
                    }
                }
                $this->stockIntelligence->reserveForHold($hold, $hold->cart_data);
                $holdTax = $existingHold ? $combinedTax : $tax;

                $staffs = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'cashier']))->get();
                foreach ($staffs as $staff) {
                    SafeEvent::dispatch(new OrderPlaced($staff->id), 'order.placed');
                }

                return response()->json([
                    'success' => true,
                    'hold_id' => $hold->id,
                    'ref_number' => $hold->ref_number,
                    'unique_code' => $uniqueCode,
                    'queue_number' => $newQueueNumber,
                    'grand_total' => $holdTax['total'],
                    'taxable_amount' => $holdTax['taxable'],
                    'tax_rate' => $holdTax['enabled'] ? $holdTax['rate'] : null,
                    'tax_amount' => $holdTax['amount'],
                    'requested_payment_method' => $requestedPayment,
                    // Struk digital untuk HP pelanggan. URL-nya ditandatangani
                    // server, jadi hanya orang yang memang menerima tautan ini
                    // yang bisa membukanya. Dikirim SEKARANG (sebelum lunas)
                    // supaya pelanggan punya satu tautan yang tetap berlaku
                    // sepanjang pesanan: menunggu pembayaran maupun setelah
                    // kasir menagih.
                    'receipt_url' => URL::signedRoute('public.menu.receipt', [
                        'ref' => $hold->ref_number,
                    ]),
                    'message' => $requestedPayment === Hold::PREFERENCE_QRIS
                        ? 'Pesanan terkirim! Tunjukkan layar ini ke kasir agar QRIS Anda dicek.'
                        : 'Pesanan Berhasil Dikirim!',
                ], 200);
            });
        } catch (StockUnavailableException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Gagal: '.$e->getMessage()], 500);
        }
    }

    public function printBill($id)
    {
        $hold = Hold::with('table')->findOrFail($id);

        $transaction = (object) [
            'invoice' => $hold->ref_number,
            'created_at' => $hold->created_at,
            'customer_name' => $hold->customer_name ?? 'Pelanggan',
            'table_name' => $hold->table->name ?? 'BAWA PULANG',
            'queue_number' => $hold->queue_number,
            'taxable_amount' => $hold->taxable_amount,
            'tax_rate' => $hold->tax_rate,
            'tax_amount' => $hold->tax_amount,
            'grand_total' => $hold->total,
            'cashier' => (object) ['name' => auth()->user()->name],
            'payment_method' => 'BELUM BAYAR',
            'cash' => 0,
            'change' => 0,
            'details' => collect($hold->cart_data)->map(function ($item) {
                return (object) [
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'notes' => $item['notes'] ?? null,
                    'product' => (object) [
                        'title' => $item['product']['title'] ?? 'Produk',
                        'sell_price' => $item['sell_price'] ?? 0,
                        'unit' => $item['unit'] ?? 'Pcs',
                    ],
                ];
            }),
        ];

        return Inertia::render('Dashboard/Transactions/Print', [
            'transaction' => $transaction,
            'receiptSetting' => ReceiptSetting::first(),
            'isBill' => true,
            'autoPrint' => true,
        ]);
    }

    public function history(Request $request, PaymentTimelineService $timeline)
    {
        return Inertia::render('Dashboard/Transactions/History', $this->historyData($request, $timeline));
    }

    /**
     * Riwayat transaksi versi kasir: full-screen, padat, dan tanpa sidebar.
     *
     * Data-nya sengaja diambil dari historyData() yang sama dengan history(),
     * bukan query sendiri. Dua halaman yang menampilkan "riwayat transaksi"
     * dengan sumber data berbeda pasti akan berbeda jawabannya setelah ada
     * transaksi baru, dan orang tidak akan tahu mana yang benar.
     */
    public function historyForCashier(Request $request, PaymentTimelineService $timeline)
    {
        return Inertia::render('Dashboard/Transactions/HistoryCashier', $this->historyData($request, $timeline));
    }

    /**
     * Data riwayat transaksi, dipakai bersama oleh history() dan
     * historyForCashier().
     *
     * Dipisah supaya kedua halaman dijamin identik: satu query, satu
     * normalisasi timeline, satu aturan akses kasir.
     *
     * @return array<string, mixed>
     */
    private function historyData(Request $request, PaymentTimelineService $timeline)
    {
        $query = Transaction::query()
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->with(['cashier:id,name', 'customer:id,name'])
            // Riwayat pembayaran ikut diambil agar tabel tidak perlu fetch ulang
            // per baris. Tanpa ini, satu halaman 10 baris akan jadi 20+ query.
            ->with('paymentSettlement')
            ->with(['paymentEvents' => fn ($q) => $q->orderBy('received_at')])
            ->withSum('details as total_items', 'qty')
            ->withSum('profits as total_profit', 'total')
            ->orderByDesc('created_at');

        if (! auth()->user()->hasRole('super-admin')) {
            $query->where('cashier_id', auth()->id());
        }

        $transactions = $query->when($request->invoice, function ($q, $invoice) {
            return $q->where('invoice', 'like', '%'.$invoice.'%');
        })
            ->when($request->payment_method, function ($q, $method) {
                return $q->where('payment_method', $method);
            })
            ->when($request->start_date, function ($q, $date) {
                return $q->whereDate('created_at', '>=', $date);
            })
            ->when($request->end_date, function ($q, $date) {
                return $q->whereDate('created_at', '<=', $date);
            })
            ->paginate(10)->withQueryString();

        // Struk digital hanya ada untuk pesanan yang datang dari menu QR.
        // Transaksi kasir biasa tidak punya `menu_ref`, jadi kolomnya kosong
        // dan frontend tidak menampilkan tombolnya.
        //
        // Riwayat pembayaran diambil dari `payment_events` — data yang sama
        // dipakai `PaymentSettlementService` saat rekonsiliasi. Kalau halaman
        // ini menghitung status sendiri, angka yang tampil bisa berbeda dari
        // yang dipakai rekonsiliasi, dan justru perbedaan itulah yang dicari
        // saat ada selisih uang.
        $transactions->getCollection()->each(function (Transaction $transaction) use ($timeline): void {
            $transaction->setAttribute('receipt_url', filled($transaction->menu_ref)
                ? URL::signedRoute('public.menu.receipt', ['ref' => $transaction->menu_ref])
                : null);

            $transaction->setAttribute('payment_summary', $timeline->summary($transaction));
            // Key-nya BUKAN `payment_events`: nama itu milik relasi Eloquent
            // `paymentEvents`, dan relasi selalu menimpa attribute dengan nama
            // sama saat serialisasi. Kalau pakai nama itu, hasil normalisasi
            // di sini diam-diam dibuang dan frontend menerima model mentah.
            $transaction->setAttribute('payment_timeline', $timeline->forTransaction($transaction));
        });

        return [
            'transactions' => $transactions,
            'filters' => $request->all(['invoice', 'start_date', 'end_date', 'payment_method']),
        ];
    }

    public function refund(Request $request, $id)
    {
        $request->validate(['password' => 'required']);

        if (! Hash::check($request->password, auth()->user()->password)) {
            return back()->withErrors(['password' => 'Password salah! Konfirmasi gagal.']);
        }

        if (! auth()->user()->hasRole('super-admin')) {
            return back()->with('error', 'Akses Ditolak! Hanya Super Admin yang memiliki otorisasi refund.');
        }

        try {
            DB::transaction(function () use ($id) {
                $transaction = Transaction::with('details.product.recipes')
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($transaction->payment_status !== 'paid') {
                    throw new \Exception('Hanya transaksi yang sudah lunas yang dapat direfund.');
                }

                if ($transaction->status === 'refunded') {
                    throw new \Exception('Transaksi ini sudah direfund sebelumnya.');
                }

                $this->stockIntelligence->restoreForTransaction($transaction);
                try {
                    $this->cogsService->reverseTransaction($transaction);
                } catch (\Throwable $exception) {
                    report($exception);
                }
                try {
                    $this->loyalty->reverseTransaction($transaction);
                } catch (\Throwable $exception) {
                    report($exception);
                }
                try {
                    // Voucher pribadi dan kuota kode promo harus dikembalikan
                    // agar bisa dipakai ulang setelah transaksi dibatalkan.
                    $this->loyalty->reverseVoucher($transaction);
                } catch (\Throwable $exception) {
                    report($exception);
                }

                $transaction->update([
                    'status' => 'refunded',
                    'refunded_at' => $transaction->refunded_at ?: now(),
                    'kitchen_status' => 'cancelled',
                ]);
                $transaction->profits()->delete();
            });

            return back()->with('success', 'Refund Berhasil! Stok telah dikembalikan ke inventori.');

        } catch (\Exception $e) {
            return back()->with('error', 'Refund Gagal: '.$e->getMessage());
        }
    }

    public function storeExpense(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            // Source menentukan account mana di neraca yang bergerak, jadi
            // dibatasi ke daftar resmi. Nilai bebas akan membuat expense
            // memotong laba tanpa mengurangi kas atau menambah hutang, dan
            // selisih neraca muncul tanpa penjelasan.
            'source' => ['nullable', 'string', Rule::in(Expense::SOURCES)],
        ]);

        $activeShift = Shift::where('user_id', auth()->id())->where('status', 'open')->first();
        $source = $request->source ?: Expense::SOURCE_CASH;

        Expense::create([
            'user_id' => auth()->id(),
            'name' => '['.$source.'] '.$request->name,
            'amount' => (float) $request->amount,
            'date' => now()->format('Y-m-d'),
            'category' => 'Operasional',
            'source' => $source,
            'note' => 'Shift #'.($activeShift->id ?? '0'),
        ]);

        return back()->with('success', $source === Expense::SOURCE_BANK_DEPOSIT
            ? 'Setor ke bank berhasil dicatat. Kas di laci berkurang, saldo bank bertambah.'
            : 'Kas keluar berhasil dicatat');
    }

    public function print($invoice)
    {
        $transaction = Transaction::with(['details.product.bundle_items.units', 'details.product_unit', 'cashier', 'customer'])->where('invoice', $invoice)->firstOrFail();

        return Inertia::render('Dashboard/Transactions/Print', [
            'transaction' => $transaction,
            'receiptSetting' => ReceiptSetting::first(),
            'isPublic' => false,
            'autoPrint' => session('auto_print', false),
        ]);
    }

    public function resumeCart($holdId)
    {
        $hold = Hold::findOrFail($holdId);
        if ($hold->transaction_id) {
            return back()->with('error', 'Pesanan sudah diproses ke pembayaran gateway.');
        }

        try {
            DB::transaction(function () use ($hold): void {
                $this->stockIntelligence->reserveForHold($hold, $hold->cart_data);
                Cart::where('cashier_id', auth()->id())->delete();
                foreach ($hold->cart_data as $item) {
                    Cart::create([
                        'cashier_id' => auth()->id(),
                        'product_id' => $item['product_id'],
                        'product_unit_id' => $item['product_unit_id'] ?? null,
                        'online_platform_id' => $item['online_platform_id'] ?? null,
                        'qty' => $item['qty'],
                        'price' => $item['price'],
                        'platform_markup' => $item['platform_markup'] ?? 0,
                        'platform_fee' => $item['platform_fee'] ?? 0,
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            });
        } catch (StockUnavailableException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Pesanan dilanjutkan dan stok direservasi.');
    }

    public function destroyHold($id)
    {
        $hold = Hold::findOrFail($id);
        if ($hold->transaction_id) {
            return back()->with('error', 'Pesanan sudah diproses ke pembayaran gateway.');
        }
        $this->stockIntelligence->releaseForHold($hold);
        if ($hold->table_id) {
            Table::where('id', $hold->table_id)->update(['status' => 'available']);
        }
        $hold->delete();
        $staffs = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'cashier']))->get();
        foreach ($staffs as $staff) {
            SafeEvent::dispatch(new OrderDeleted($staff->id), 'order.deleted');
        }

        return back();
    }

    public function destroyAll(Request $request)
    {
        $request->validate(['password' => 'required']);
        if (! Hash::check($request->password, auth()->user()->password)) {
            return back()->withErrors(['password' => 'Password salah!']);
        }
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            $tables = ['payment_events', 'payment_settlements', 'stock_batch_allocations', 'loyalty_point_ledger', 'customer_vouchers', 'stock_reservations', 'transaction_details', 'transaction_profits', 'transactions', 'stock_batches', 'stock_movements', 'profits', 'expenses', 'shifts', 'carts', 'holds'];
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                    DB::statement("ALTER TABLE $table AUTO_INCREMENT = 1");
                }
            }
            DB::table('products')->update(['stock' => 0]);
            DB::table('ingredients')->update(['stock' => 0]);
            DB::table('tables')->update(['status' => 'available']);
            if (Schema::hasTable('customers')) {
                DB::table('customers')->update([
                    'loyalty_points' => 0,
                    'lifetime_spend' => 0,
                    'loyalty_tier' => 'bronze',
                    'last_visit_at' => null,
                ]);
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            \Illuminate\Support\Facades\Artisan::call('optimize:clear');

            return redirect()->route('profile.edit')->with('success', 'Sistem berhasil direset total!');
        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            return back()->with('error', 'Gagal reset: '.$e->getMessage());
        }
    }

    public function showPublic($invoice)
    {
        $transaction = Transaction::with(['details.product', 'cashier', 'customer'])->where('invoice', $invoice)->firstOrFail();

        return Inertia::render('Dashboard/Transactions/Print', [
            'transaction' => $transaction,
            'receiptSetting' => ReceiptSetting::first(),
            'isPublic' => true,
            'autoPrint' => false,
        ]);
    }
}
