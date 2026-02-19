<?php

namespace App\Http\Controllers\Apps;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ReceiptSetting;
use App\Models\Transaction;
use App\Models\Shift;
use App\Models\Hold;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\Table;
use App\Models\Recipe; 
use App\Models\Ingredient; 
use App\Models\User;
use App\Models\OnlineSetting; 
use App\Events\OrderPlaced; 
use App\Events\OrderDeleted; 
use App\Services\CogsService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TransactionController extends Controller
{
    protected $cogsService;

    public function __construct(CogsService $cogsService)
    {
        $this->cogsService = $cogsService;
    }

    /**
     * Helper Internal: Menambahkan data produk dan bundling ke dalam array cart
     */
    private function resolveBundlingRelasi($cartItems)
    {
        $processed = [];
        foreach ($cartItems as $item) {
            $product = Product::with(['bundle_items.units', 'units'])->find($item['product_id']);
            if ($product) { $item['product'] = $product; }
            $processed[] = $item;
        }
        return $processed;
    }

    /**
     * SOURCE OF TRUTH: Menghitung Harga Satuan setelah Diskon Dashboard
     */
    private function getDiscountedUnitPrice($productId, $basePrice)
    {
        $autoPromo = Discount::active()
            ->where('product_id', $productId)
            ->whereNull('bonus_product_id')
            ->first();

        if ($autoPromo) {
            if ($autoPromo->type === 'percentage') {
                return (float) ($basePrice - ($basePrice * ($autoPromo->value / 100)));
            }
            return (float) ($basePrice - $autoPromo->value);
        }
        return (float) $basePrice;
    }

    /**
     * Tampilan Utama Kasir
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $userId = $user->id;
        $activeShift = Shift::where('user_id', $userId)->where('status', 'open')->first();

        $carts = Cart::with(['product.units', 'product.bundle_items.units', 'unit'])
            ->where('cashier_id', $userId)->active()->latest()->get();

        $cartsTotalAmount = (int) $carts->sum('price');

        $holds = Hold::with('table')->latest()->get();
        $tables = Table::orderBy('name')->get();

        $productsQuery = Product::with(['category:id,name', 'bundle_items', 'units', 'recipes'])
            ->select('id', 'barcode', 'title', 'description', 'image', 'buy_price', 'sell_price', 'stock', 'category_id', 'type', 'unit')
            ->where(function ($query) {
                $query->where(function($q) {
                    $q->where('type', '!=', 'bundle')
                      ->where(function($sub) {
                          $sub->where('stock', '>', 0)
                              ->orHas('recipes'); 
                      });
                })
                ->orWhere(function($q) {
                    $q->where('type', 'bundle')
                      ->whereHas('bundle_items', function($sub) {
                          $sub->where('stock', '>', 0);
                      });
                });
            })
            ->when($request->search, function($query, $search) {
                $query->where(function($q) use ($search) {
                    $q->where('title', 'like', '%'.$search.'%')
                      ->orWhere('barcode', 'like', '%'.$search.'%');
                });
            })
            ->when($request->category_id, function($query, $catId) {
                if ($catId !== 'all' && $catId) {
                    $query->where('category_id', $catId);
                }
            })
            ->orderBy('title');

        if ($request->wantsJson()) {
            return response()->json($productsQuery->paginate(15)->withQueryString());
        }

        return Inertia::render('Dashboard/Transactions/Index', [
            'carts'           => $carts,
            'carts_total'     => $cartsTotalAmount, 
            'holds'           => $holds,
            'tables'          => $tables,
            'customers'       => Customer::select('id', 'name', 'phone')->latest()->get(),
            'products'        => $productsQuery->paginate(15)->withQueryString(), 
            'categories'      => Category::select('id', 'name', 'image')->orderBy('name')->get(),
            'discounts'       => Discount::active()->with(['product:id,title', 'bonusProduct:id,title'])->get(),
            'paymentSetting'  => PaymentSetting::first(),
            'activeShift'     => $activeShift,
            'receiptSetting'  => ReceiptSetting::first(),
            'onlineSettings'  => OnlineSetting::where('is_active', true)->get(), 
            'filters'         => $request->all(['search', 'category_id']),
        ]);
    }

    public function addToCart(Request $request)
    {
        $userId = auth()->id();
        $product = Product::findOrFail($request->product_id);
        $unitId = $request->product_unit_id ?? null;
        
        if ($request->has('price')) {
            $unitPrice = (float) $request->price;
        } else {
            $basePrice = $unitId ? (ProductUnit::find($unitId)->sell_price ?? $product->sell_price) : $product->sell_price;
            $unitPrice = $this->getDiscountedUnitPrice($product->id, $basePrice);
        }

        DB::transaction(function () use ($userId, $product, $unitId, $unitPrice, $request) {
            $cart = Cart::where(['product_id' => $product->id, 'product_unit_id' => $unitId, 'cashier_id' => $userId, 'notes' => $request->notes ?? null])->first();
            if ($cart) { 
                $cart->increment('qty', 1); 
                $cart->update(['price' => $unitPrice * $cart->fresh()->qty]); 
            } else { 
                $cart = Cart::create([
                    'cashier_id' => $userId, 
                    'product_id' => $product->id, 
                    'product_unit_id' => $unitId, 
                    'qty' => 1, 
                    'price' => $unitPrice, 
                    'notes' => $request->notes ?? null
                ]); 
            }

            $bonusPromo = Discount::active()->where('product_id', $product->id)->whereNotNull('bonus_product_id')->first();
            if ($bonusPromo) {
                $minBuy = (int) ($bonusPromo->minimum_item ?: 2);
                $expectedBonusQty = (int) floor($cart->fresh()->qty / $minBuy);
                Cart::where(['product_id' => $bonusPromo->bonus_product_id, 'cashier_id' => $userId, 'notes' => 'BONUS PROMO'])->delete();
                if ($expectedBonusQty > 0) {
                    Cart::create(['cashier_id' => $userId, 'product_id' => $bonusPromo->bonus_product_id, 'qty' => $expectedBonusQty, 'price' => 0, 'notes' => 'BONUS PROMO']);
                }
            }
        });
        return back();
    }

    public function updateCart(Request $request, $cart_id)
    {
        $userId = auth()->id();
        $cart = Cart::with('product')->whereId($cart_id)->firstOrFail();
        if ($cart->notes === 'BONUS PROMO') return back();

        DB::transaction(function () use ($request, $cart, $userId) {
            $unitId = $request->product_unit_id ?? $cart->product_unit_id;
            $unitPriceAtCart = $cart->qty > 0 ? ($cart->price / $cart->qty) : $cart->price;
            
            if($request->product_unit_id && $request->product_unit_id != $cart->product_unit_id) {
                $basePrice = ProductUnit::find($unitId)->sell_price ?? $cart->product->sell_price;
                $unitPrice = $this->getDiscountedUnitPrice($cart->product_id, $basePrice);
            } else {
                $unitPrice = $unitPriceAtCart;
            }

            $cart->update([
                'qty' => $request->qty, 
                'product_unit_id' => $unitId, 
                'price' => $unitPrice * $request->qty, 
                'notes' => $request->notes
            ]);
            
            $bonusPromo = Discount::active()->where('product_id', $cart->product_id)->whereNotNull('bonus_product_id')->first();
            if ($bonusPromo) {
                $minBuy = (int) ($bonusPromo->minimum_item ?: 2);
                $expectedBonus = (int) floor($cart->fresh()->qty / $minBuy);
                Cart::where(['product_id' => $bonusPromo->bonus_product_id, 'cashier_id' => $userId, 'notes' => 'BONUS PROMO'])->delete();
                if ($expectedBonus > 0) {
                    Cart::create(['cashier_id' => $userId, 'product_id' => $bonusPromo->bonus_product_id, 'qty' => $expectedBonus, 'price' => 0, 'notes' => 'BONUS PROMO']);
                }
            }
        });
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

    /**
     * SIMPAN TRANSAKSI
     */
    public function store(Request $request, PaymentGatewayManager $paymentGatewayManager)
    {
        $activeShift = Shift::where('user_id', auth()->id())->where('status', 'open')->first();
        if (!$activeShift) {
            return response()->json(['message' => 'Shift belum dibuka!'], 403);
        }

        $paymentSetting = PaymentSetting::first();
        $method         = strtolower($request->payment_gateway ?? 'cash');
        
        $isGateway = in_array($method, [PaymentSetting::GATEWAY_MIDTRANS, PaymentSetting::GATEWAY_XENDIT]);
        $paymentStatus = $isGateway ? 'pending' : 'paid';

        $cash   = (float) str_replace(',', '', $request->cash ?? 0);
        $change = (float) str_replace(',', '', $request->change ?? 0);
        
        $cogsMethod = Setting::first()->cogs_method ?? 'AVERAGE';
        $invoice = 'TRX-' . Str::upper(Str::random(10));
        
        try {
            $transaction = DB::transaction(function () use ($request, $invoice, $activeShift, $cogsMethod, $cash, $change, $paymentStatus, $method, $isGateway) {
                
                $cartItems = Cart::with(['product.bundle_items', 'product.units', 'product.recipes'])->where('cashier_id', auth()->id())->get();
                if ($cartItems->isEmpty()) { throw new \Exception('Keranjang kosong'); }

                $finalQueueNumber = $request->queue_number;
                if (!$finalQueueNumber) {
                    $todayCount = Transaction::whereDate('created_at', now())->count() + Hold::whereDate('created_at', now())->count();
                    $finalQueueNumber = 'Q-' . str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);
                }

                $subtotalKeranjang = (float) $cartItems->sum('price');
                $diskonOtomatisGlobal = 0;
                $promoGlobal = Discount::active()
                    ->whereNull('product_id') 
                    ->where('min_transaction', '<=', $subtotalKeranjang)
                    ->orderBy('min_transaction', 'desc')
                    ->first();

                if ($promoGlobal) {
                    if ($promoGlobal->type === 'percentage') {
                        $diskonOtomatisGlobal = $subtotalKeranjang * ($promoGlobal->value / 100);
                    } else if ($promoGlobal->type === 'fixed') {
                        $diskonOtomatisGlobal = (float) $promoGlobal->value;
                    }
                }

                $grandTotalFinal = $subtotalKeranjang - $diskonOtomatisGlobal;

                $transaction = Transaction::create([
                    'cashier_id'      => auth()->id(),
                    'customer_id'     => $request->customer_id,
                    'shift_id'        => $activeShift->id,
                    'invoice'         => $invoice,
                    'reference_code'  => $request->reference_code ?? Str::random(4), 
                    'customer_name'   => $request->customer_name ?? 'Pelanggan', 
                    'cash'            => $cash,
                    'change'          => $change,
                    'discount'        => $diskonOtomatisGlobal, 
                    'grand_total'     => $grandTotalFinal,
                    'payment_method'  => $method,
                    'payment_status'  => $paymentStatus,
                    'table_name'      => $request->table_name ?: 'BAWA PULANG', 
                    'queue_number'    => $finalQueueNumber, 
                    'online_platform' => $request->online_platform,
                    'total_markup'    => (float)$request->total_markup,
                    'total_fee'       => (float)$request->total_fee,
                ]);

                foreach ($cartItems as $cart) {
                    $product = $cart->product;
                    $totalItemSellingPrice = (float) $cart->price;
                    
                    $totalItemCost = 0;
                    if ($product->type === 'bundle') {
                        foreach ($product->bundle_items as $item) {
                            $bundleConversion = $item->pivot->product_unit_id ? (ProductUnit::find($item->pivot->product_unit_id)->conversion ?? 1) : 1;
                            $totalItemCost += $this->cogsService->calculate($item->id, $cart->qty * $item->pivot->qty * $bundleConversion, $cogsMethod);
                        }
                    } else {
                        $conversionValue = $cart->product_unit_id ? (ProductUnit::find($cart->product_unit_id)->conversion ?? 1) : 1;
                        $totalItemCost = $this->cogsService->calculate($cart->product_id, $cart->qty * $conversionValue, $cogsMethod);
                    }

                    $itemProportion = $subtotalKeranjang > 0 ? ($totalItemSellingPrice / $subtotalKeranjang) : 0;
                    $itemDiscountAllocation = $diskonOtomatisGlobal * $itemProportion;
                    $appCommissionJatah = ((float)$request->total_markup + (float)$request->total_fee) * $itemProportion;
                    
                    $realNetProfit = $totalItemSellingPrice - $itemDiscountAllocation - $appCommissionJatah - $totalItemCost;

                    // --- FIX: POTONG STOK HANYA JIKA BAYAR TUNAI (CASH) ---
                    // Jika QRIS/Midtrans, stok dipotong nanti lewat Webhook setelah dibayar lunas
                    if (!$isGateway) {
                        if ($product->recipes->count() > 0) {
                            foreach ($product->recipes as $recipe) {
                                DB::table('ingredients')->where('id', $recipe->ingredient_id)
                                    ->decrement('stock', (float)$recipe->qty_needed * (float)$cart->qty);
                            }
                        } 
                        else if ($product->type !== 'bundle') {
                            $conversion = 1;
                            if ($cart->product_unit_id) {
                                $unit = ProductUnit::find($cart->product_unit_id);
                                $conversion = $unit ? $unit->conversion : 1;
                            }
                            DB::table('products')->where('id', $cart->product_id)->decrement('stock', $cart->qty * $conversion);
                        }
                    }

                    $transaction->details()->create([
                        'product_id'      => $cart->product_id,
                        'qty'             => $cart->qty,
                        'price'           => $totalItemSellingPrice, 
                        'buy_price'       => $cart->qty > 0 ? ($totalItemCost / $cart->qty) : 0, 
                        'unit'            => $cart->unit->unit_name ?? $product->unit ?? 'Pcs',
                        'product_unit_id' => $cart->product_unit_id,
                        'notes'           => $cart->notes,
                    ]);

                    $transaction->profits()->create(['total' => (float)$realNetProfit]);
                }

                if ($request->hold_id) {
                    $hold = Hold::find($request->hold_id);
                    if ($hold) {
                        if ($hold->table_id) { Table::where('id', $hold->table_id)->update(['status' => 'available']); }
                        $hold->delete();
                        $staffs = User::role(['super-admin', 'cashier'])->get();
                        foreach ($staffs as $staff) { event(new OrderDeleted($staff->id)); }
                    }
                }

                // --- FIX: JANGAN HAPUS CART JIKA PAKAI GATEWAY (MIDTRANS) ---
                // Cart baru dihapus jika Tunai atau saat Webhook menyatakan 'settlement'
                if (!$isGateway) {
                    Cart::where('cashier_id', auth()->id())->delete();
                }
                
                return $transaction;
            });

            // HANDLE RESPONSE BERDASARKAN METODE
            if ($isGateway) {
                try {
                    $paymentResponse = $paymentGatewayManager->createPayment($transaction, $method, $paymentSetting);
                    return response()->json([
                        'success'     => true,
                        'payment_url' => $paymentResponse['payment_url'],
                        'token'       => $paymentResponse['token'] ?? null,
                        'invoice'     => $transaction->invoice
                    ]);
                } catch (PaymentGatewayException $e) {
                    DB::rollBack(); 
                    return response()->json(['message' => 'Gagal koneksi ke Gateway: ' . $e->getMessage()], 500);
                }
            }

            return response()->json([
                'success' => true,
                'invoice' => $transaction->invoice,
                'message' => 'Transaksi Berhasil'
            ]);

        } catch (\Exception $e) { 
            return response()->json(['message' => 'Transaksi Gagal: ' . $e->getMessage()], 500); 
        }
    }

    public function holdCart(Request $request)
    {
        $request->validate(['cart_items' => 'required', 'total' => 'required']);
        try {
            DB::transaction(function() use ($request) {
                $processedCart = $this->resolveBundlingRelasi($request->cart_items);
                $cleanSubtotal = (float) collect($processedCart)->sum('price');

                $diskonGlobal = 0;
                $promo = Discount::active()->whereNull('product_id')->where('min_transaction', '<=', $cleanSubtotal)->orderBy('min_transaction', 'desc')->first();
                if ($promo) {
                    $diskonGlobal = ($promo->type === 'percentage') ? $cleanSubtotal * ($promo->value / 100) : (float)$promo->value;
                }

                $finalTotal = $cleanSubtotal - $diskonGlobal;
                
                $todayCount = Transaction::whereDate('created_at', now())->count() + Hold::whereDate('created_at', now())->count();
                $newQueueNumber = 'Q-' . str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);
                $uniqueCode = (string) rand(1000, 9999);
                $tableId = ($request->table_id === 'take_away' || !$request->table_id) ? null : $request->table_id;

                if ($request->hold_id) {
                    $hold = Hold::findOrFail($request->hold_id);
                    if ($hold->table_id && $hold->table_id != $request->table_id) { Table::where('id', $hold->table_id)->update(['status' => 'available']); }
                    $hold->update(['cart_data' => $processedCart, 'total' => $finalTotal, 'table_id' => $tableId, 'customer_name' => $request->customer_name]);
                } else {
                    Hold::create([
                        'ref_number' => 'ORD-'.$uniqueCode, 
                        'reference_code' => $uniqueCode, 
                        'queue_number' => $newQueueNumber, 
                        'customer_name' => $request->customer_name, 
                        'table_id' => $tableId, 
                        'cart_data' => $processedCart, 
                        'total' => $finalTotal, 
                        'user_id' => auth()->id()
                    ]);
                }
                if ($tableId) { Table::where('id', $tableId)->update(['status' => 'occupied']); }
                Cart::where('cashier_id', auth()->id())->delete();
                
                $staffs = User::role(['super-admin', 'cashier'])->get();
                foreach ($staffs as $staff) { 
                    event(new OrderPlaced($staff->id)); 
                }
            });
            return back()->with('success', 'Diproses.');
        } catch (\Exception $e) { return back()->with('error', $e->getMessage()); }
    }

    public function customerOrder(Request $request)
    {
        $request->validate(['cart_items' => 'required|array', 'customer_name' => 'required', 'total' => 'required']);
        try {
            return DB::transaction(function() use ($request) {
                $targetUserId = User::role('super-admin')->first()->id ?? 1;
                $processedCart = $this->resolveBundlingRelasi($request->cart_items);
                $cleanSubtotal = (float) collect($processedCart)->sum('price');
                
                $diskonGlobal = 0;
                $promo = Discount::active()->whereNull('product_id')->where('min_transaction', '<=', $cleanSubtotal)->orderBy('min_transaction', 'desc')->first();
                if ($promo) {
                    $diskonGlobal = ($promo->type === 'percentage') ? $cleanSubtotal * ($promo->value / 100) : (float)$promo->value;
                }
                $finalTotal = $cleanSubtotal - $diskonGlobal;

                $tableId = ($request->order_type === 'takeaway' || !$request->table_id) ? null : $request->table_id;
                $todayCount = Transaction::whereDate('created_at', now())->count() + Hold::whereDate('created_at', now())->count();
                $newQueueNumber = 'Q-' . str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);
                $uniqueCode = (string) rand(1000, 9999);
                $finalNameWithCode = trim($request->customer_name) . ' #' . $uniqueCode;

                $existingHold = $tableId ? Hold::where('table_id', $tableId)->first() : null;
                if ($existingHold) {
                    $existingHold->update(['cart_data' => array_merge($existingHold->cart_data, $processedCart), 'total' => $existingHold->total + $finalTotal, 'queue_number' => $newQueueNumber, 'customer_name' => $finalNameWithCode]);
                } else {
                    Hold::create([
                        'ref_number' => 'QR-'.$uniqueCode, 
                        'reference_code' => $uniqueCode, 
                        'queue_number' => $newQueueNumber, 
                        'customer_name' => $finalNameWithCode, 
                        'table_id' => $tableId, 
                        'cart_data' => $processedCart, 
                        'total' => $finalTotal, 
                        'user_id' => $targetUserId
                    ]);
                    if ($tableId) { Table::where('id', $tableId)->update(['status' => 'occupied']); }
                }
                
                $staffs = User::role(['super-admin', 'cashier'])->get();
                foreach ($staffs as $staff) { 
                    event(new OrderPlaced($staff->id)); 
                }

                return response()->json(['success' => true, 'unique_code' => $uniqueCode, 'queue_number' => $newQueueNumber, 'message' => 'Pesanan Berhasil Dikirim!'], 200);
            });
        } catch (\Exception $e) { return response()->json(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()], 500); }
    }

    public function history(Request $request)
    {
        $query = Transaction::query()->with(['cashier:id,name', 'customer:id,name'])->withSum('details as total_items', 'qty')->withSum('profits as total_profit', 'total')->orderByDesc('created_at');
        if (!auth()->user()->hasRole('super-admin')) { $query->where('cashier_id', auth()->id()); }
        $transactions = $query->when($request->invoice, function($q, $invoice) { return $q->where('invoice', 'like', "%" . $invoice . "%"); })
            ->when($request->start_date, function($q, $date) { return $q->whereDate('created_at', '>=', $date); })
            ->when($request->end_date, function($q, $date) { return $q->whereDate('created_at', '<=', $date); })
            ->paginate(10)->withQueryString();
        return Inertia::render('Dashboard/Transactions/History', [
            'transactions' => $transactions, 
            'filters' => $request->all(['invoice', 'start_date', 'end_date'])
        ]);
    }

    public function storeExpense(Request $request) 
    { 
        $request->validate(['name' => 'required|string|max:255', 'amount' => 'required|numeric|min:0']);
        $activeShift = Shift::where('user_id', auth()->id())->where('status', 'open')->first(); 

        Expense::create([
            'user_id'  => auth()->id(), 
            'name'     => '[KASIR] ' . $request->name, 
            'amount'   => (float)$request->amount, 
            'date'     => now()->format('Y-m-d'), 
            'category' => 'Operasional', 
            'source'   => 'Kas Laci', 
            'note'     => 'Shift #' . ($activeShift->id ?? '0')
        ]); 

        return back()->with('success', 'Kas keluar berhasil dicatat'); 
    }

    public function print($invoice) { 
        $transaction = Transaction::with(['details.product.bundle_items.units', 'details.product_unit', 'cashier', 'customer'])->where('invoice', $invoice)->firstOrFail(); 
        return Inertia::render('Dashboard/Transactions/Print', [
            'transaction' => $transaction, 
            'receiptSetting' => ReceiptSetting::first(), 
            'isPublic' => false, 
            'autoPrint' => session('auto_print', false)
        ]); 
    }
    
    public function resumeCart($holdId) { 
        $hold = Hold::findOrFail($holdId); 
        Cart::where('cashier_id', auth()->id())->delete(); 
        foreach ($hold->cart_data as $item) { 
            Cart::create([
                'cashier_id' => auth()->id(), 
                'product_id' => $item['product_id'], 
                'product_unit_id' => $item['product_unit_id'] ?? null, 
                'qty' => $item['qty'], 
                'price' => $item['price'], 
                'notes' => $item['notes'] ?? null
            ]); 
        } 
        return back(); 
    }
    
    public function destroyHold($id) { 
        $hold = Hold::findOrFail($id); 
        if ($hold->table_id) { Table::where('id', $hold->table_id)->update(['status' => 'available']); } 
        $hold->delete(); 
        $staffs = User::role(['super-admin', 'cashier'])->get(); 
        foreach ($staffs as $staff) { 
            event(new OrderDeleted($staff->id)); 
        } 
        return back(); 
    }

    public function destroyAll(Request $request)
    {
        $request->validate(['password' => 'required']);
        if (!Hash::check($request->password, auth()->user()->password)) {
            return back()->withErrors(['password' => 'Password yang Anda masukkan salah!']);
        }
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            $tables = ['transaction_details', 'transaction_profits', 'transactions', 'profits', 'expenses', 'shifts', 'carts', 'holds'];
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                    DB::statement("ALTER TABLE $table AUTO_INCREMENT = 1");
                }
            }
            DB::table('products')->update(['stock' => 0]);
            DB::table('ingredients')->update(['stock' => 0]);
            DB::table('tables')->update(['status' => 'available']);
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
            \Illuminate\Support\Facades\Artisan::call('optimize:clear');
            \Illuminate\Support\Facades\Artisan::call('view:clear');
            return redirect()->route('profile.edit')->with('success', 'Sistem berhasil direset total ke nol!');
        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            return back()->with('error', 'Gagal reset sistem: ' . $e->getMessage());
        }
    }

    public function showPublic($invoice)
    {
        $transaction = Transaction::with(['details.product', 'cashier', 'customer'])
            ->where('invoice', $invoice)
            ->firstOrFail();

        return Inertia::render('Dashboard/Transactions/Print', [
            'transaction'    => $transaction,
            'receiptSetting' => ReceiptSetting::first(),
            'isPublic'       => true, 
            'autoPrint'      => false
        ]);
    }
}