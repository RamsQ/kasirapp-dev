<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\Apps\AnalyticsController;
use App\Http\Controllers\Apps\CategoryController;
use App\Http\Controllers\Apps\CrmCampaignController;
use App\Http\Controllers\Apps\CustomerController;
use App\Http\Controllers\Apps\DiscountController;
use App\Http\Controllers\Apps\ExpiredProductController;
use App\Http\Controllers\Apps\IngredientController;
use App\Http\Controllers\Apps\LoyaltyRuleController;
use App\Http\Controllers\Apps\OnlineSettingController;
use App\Http\Controllers\Apps\PaymentSettingController;
use App\Http\Controllers\Apps\PaymentSettlementController;
use App\Http\Controllers\Apps\ProductController;
use App\Http\Controllers\Apps\ProductReportController;
use App\Http\Controllers\Apps\ProfileController;
use App\Http\Controllers\Apps\PublicMenuController;
use App\Http\Controllers\Apps\PublicMenuReceiptController;
use App\Http\Controllers\Apps\ReceiptSettingController;
use App\Http\Controllers\Apps\RecipeController;
use App\Http\Controllers\Apps\ReportSettingController;
use App\Http\Controllers\Apps\SettingController;
use App\Http\Controllers\Apps\StockInController;
use App\Http\Controllers\Apps\StockOpnameController;
use App\Http\Controllers\Apps\TableController; // Tambahkan Import Controller Baru
use App\Http\Controllers\Apps\TransactionController;
use App\Http\Controllers\Auth\FaceAuthController;
use App\Http\Controllers\CapitalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KitchenDisplayController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Reports\ProfitReportController;
use App\Http\Controllers\Reports\RefundReportController;
use App\Http\Controllers\Reports\SalesReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\UserController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes - LakuPOS
|--------------------------------------------------------------------------
*/

// =============================================================
// 1. RUTE PUBLIK & SELF-ORDERING (Tanpa Login / Guest)
// =============================================================
Route::get('/', function () {
    return Inertia::render('Auth/Login', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Fitur QR Menu Pelanggan (Wajib di luar group 'auth')
Route::prefix('menu')->group(function () {
    Route::get('/{table_id?}', [PublicMenuController::class, 'index'])->name('public.menu');
    Route::post('/order', [TransactionController::class, 'customerOrder'])->name('public.menu.order');
});

/**
 * Struk digital untuk pelanggan pesanan menu QR.
 *
 * WAJIB signed. Halaman ini terbuka tanpa login, jadi tanpa signature
 * siapa pun bisa menebak ref_number (misal QR-0001) dan melihat pesanan
 * orang lain. Middleware `signed` menolak URL yang signature-nya tidak cocok
 * atau parameter-nya sudah diubah.
 *
 * Header `Cache-Control: no-store` disetel di controller supaya halaman ini
 * tidak ikut tersimpan di cache browser atau proxy di tengah jalan.
 */
Route::get('/menu/struk/{ref}', [PublicMenuReceiptController::class, 'show'])
    ->middleware('signed')
    ->name('public.menu.receipt');

// Fitur Auth Tambahan & Share Invoice
Route::post('/face-auth/fetch-user', [FaceAuthController::class, 'fetchUser'])->name('face.fetch');
Route::post('/face-auth/login', [FaceAuthController::class, 'login'])->name('face.login');
Route::get('/share/invoice/{invoice}', [TransactionController::class, 'shareInvoice'])->name('transactions.share');

// Rute Publik untuk Validasi Struk Digital (Tanpa Login)
Route::get('/p/invoice/{invoice}', [TransactionController::class, 'showPublic'])->name('transactions.show_public');

// =============================================================
// 2. RUTE PRIVATE (Dashboard - Wajib Login)
// =============================================================
Route::group(['prefix' => 'dashboard', 'middleware' => ['auth']], function () {

    // [0] DASHBOARD UTAMA
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // [0.1] PROFIL & PERSONAL SETTINGS
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.edit');
    Route::match(['post', 'put', 'patch'], '/profile', [ProfileController::class, 'update'])
        ->name('profile.update');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/face-update', [ProfileController::class, 'updateFace'])->name('profile.face.update');
    Route::post('/face-registration-alt', [ProfileController::class, 'updateFace'])->name('face.register');

    // [0.2] AI BUSINESS COACH
    Route::get('/ai-coach', [AiController::class, 'index'])
        ->middleware('permission:ai-access')
        ->name('ai.index');
    Route::post('/ai-coach/chat', [AiController::class, 'chat'])
        ->middleware(['permission:ai-access', 'throttle:ai-chat'])
        ->name('ai.chat');

    // Danger Zone: Reset System
    Route::post('/profile/reset-system', [TransactionController::class, 'destroyAll'])
        ->name('system.reset')->middleware(['role:super-admin']);

    // =============================================================
    // 3. MODUL USER MANAGEMENT
    // =============================================================
    Route::group(['middleware' => ['permission:users.index']], function () {
        Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::resource('/roles', RoleController::class)->except(['create', 'edit', 'show']);
        Route::resource('/users', UserController::class)->except('show');
    });

    // =============================================================
    // 4. MODUL KATALOG & MASTER DATA
    // =============================================================
    Route::group(['middleware' => ['permission:products.index']], function () {
        // Controller tidak memiliki method show(); halaman detail memakai
        // edit. Route show sengaja tidak didaftarkan agar tidak menjadi
        // dead-end yang balas 500.
        Route::resource('categories', CategoryController::class)->except('show');

        // Template Produk
        Route::get('/products/template', [ProductController::class, 'template'])->name('products.template');

        // Produk Resource
        Route::resource('products', ProductController::class)->except('show');
        Route::post('/products/import', [ProductController::class, 'import'])->name('products.import');
        Route::post('/products/bulk-destroy', [ProductController::class, 'bulkDestroy'])->name('products.bulk_destroy');

        // Manajemen Meja
        Route::resource('/tables', TableController::class)->except(['create', 'edit', 'show']);
        Route::get('/tables/print-qr', [TableController::class, 'printQr'])->name('tables.printQr');

        // Cetak QR Take Away
        Route::get('/tables/print-qr-takeaway', function () {
            return Inertia::render('Dashboard/Tables/PrintQRTakeAway');
        })->name('print.qr.takeaway');
    });

    // =============================================================
    // 5. MODUL WAREHOUSE & INVENTORY
    // =============================================================
    Route::group(['middleware' => ['permission:ingredients.index']], function () {
        // --- INGREDIENTS ---
        Route::resource('/ingredients', IngredientController::class)->except(['create', 'edit', 'show']);
        Route::get('/ingredients-template', [IngredientController::class, 'template'])->name('ingredients.template');

        // --- RECIPES (PENGATURAN HPP) ---
        Route::get('/recipes', [RecipeController::class, 'index'])->name('recipes.index');
        Route::post('/recipes', [RecipeController::class, 'store'])->name('recipes.store');
        Route::get('/recipes/template', [RecipeController::class, 'template'])->name('recipes.template');
        Route::post('/recipes/import', [RecipeController::class, 'import'])->name('recipes.import');
        Route::post('/recipes/sync-all', [RecipeController::class, 'syncAll'])->name('recipes.sync_all');
        Route::delete('/recipes/{id}', [RecipeController::class, 'destroy'])->name('recipes.destroy');

        // --- STOCK-IN MODULE ---
        Route::prefix('stock-in')->group(function () {
            Route::get('/', [StockInController::class, 'index'])->name('stock_in.index');
            Route::post('/store', [StockInController::class, 'store'])->name('stock_in.store');
            Route::get('/batch/{id}/{type}', [StockInController::class, 'getBatchDetail'])->name('stock_in.batch_detail');
            Route::get('/export', [StockInController::class, 'export'])->name('stock_in.export');
            Route::get('/template-product', [StockInController::class, 'exportProductTemplate'])->name('stock_in.template_product');
            Route::get('/template-ingredient', [StockInController::class, 'exportIngredientTemplate'])->name('stock_in.template_ingredient');
            Route::post('/parse-excel', [StockInController::class, 'parseExcel'])->name('stock_in.parse_excel');
        });

        // --- STOCK OPNAMES ---
        Route::get('/stock-opnames', [StockOpnameController::class, 'index'])->name('stock_opnames.index');
        Route::post('/stock-opnames', [StockOpnameController::class, 'store'])->name('stock_opnames.store');
        Route::get('/stock-opnames/template-product', [StockOpnameController::class, 'exportProductTemplate'])->name('stock_opnames.template_product');
        Route::get('/stock-opnames/template-ingredient', [StockOpnameController::class, 'exportIngredientTemplate'])->name('stock_opnames.template_ingredient');
    });

    // =============================================================
    // 6. MODUL TRANSAKSI & KASIR
    // =============================================================
    Route::group(['middleware' => ['permission:transactions.index|transactions-access']], function () {
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::post('/transactions/store', [TransactionController::class, 'store'])->name('transactions.store');
        Route::get('/transactions/history', [TransactionController::class, 'history'])->name('transactions.history');

        // Versi kasir: full-screen tanpa sidebar, dibuka langsung dari header
        // halaman kasir. Data-nya dari historyData() yang sama dengan
        // /transactions/history di atas, jadi kedua halaman tidak mungkin
        // menampilkan riwayat yang berbeda.
        Route::get('/transactions/riwayat', [TransactionController::class, 'historyForCashier'])
            ->name('transactions.history.cashier');
        Route::get('/transactions/{invoice}/print', [TransactionController::class, 'print'])->name('transactions.print');
        Route::post('/transactions/{transaction}/refund', [TransactionController::class, 'refund'])->name('transactions.refund');
        Route::post('/transactions/expense', [TransactionController::class, 'storeExpense'])->name('expenses.store');

        Route::post('/transactions/addToCart', [TransactionController::class, 'addToCart'])->name('transactions.addToCart');
        Route::patch('/transactions/{cart_id}/updateCart', [TransactionController::class, 'updateCart'])->name('transactions.updateCart');
        Route::delete('/transactions/{cart_id}/destroyCart', [TransactionController::class, 'destroyCart'])->name('transactions.destroyCart');

        Route::post('/transactions/hold', [TransactionController::class, 'holdCart'])->name('transactions.hold');
        Route::post('/transactions/resume/{holdId}', [TransactionController::class, 'resumeCart'])->name('transactions.resume');
        Route::post('/transactions/move-table/{holdId}', [TransactionController::class, 'moveTable'])->name('transactions.move_table');
        Route::post('/transactions/merge-table', [TransactionController::class, 'mergeTable'])->name('transactions.merge_table');

        Route::delete('/holds/{id}', [TransactionController::class, 'destroyHold'])->name('transactions.destroyHold');
        Route::delete('/holds-legacy/{id}', [TransactionController::class, 'destroyHold'])->name('holds.destroy');

        Route::get('/transactions/print-bill/{id}', [TransactionController::class, 'printBill'])->name('transactions.printBill');

        // Rute Pembatalan Gateway jika pop-up ditutup tanpa bayar
        Route::post('/transactions/cancel-gateway', [TransactionController::class, 'cancelGateway'])->name('transactions.cancel_gateway');
    });

    // =============================================================
    // 6.1 KITCHEN DISPLAY (OPSIONAL)
    // =============================================================
    Route::group(['middleware' => ['permission:kitchen-display.index']], function () {
        Route::get('/kitchen', [KitchenDisplayController::class, 'index'])->name('kitchen.index');
        Route::get('/kitchen/orders', [KitchenDisplayController::class, 'orders'])->name('kitchen.orders');
        Route::post('/kitchen/orders/{type}/{id}/status', [KitchenDisplayController::class, 'updateStatus'])
            ->whereIn('type', ['transaction', 'hold'])
            ->name('kitchen.status');
    });

    // =============================================================
    // ANALITIK
    //
    // Permission-nya sendiri (analytics-access), terpisah dari reports.index.
    // Analitik menampilkan laba dan margin, jadi tidak otomatis dibuka untuk
    // siapa saja yang boleh melihat daftar penjualan.
    Route::get('/analytics', [AnalyticsController::class, 'index'])
        ->name('reports.analytics')
        ->middleware('permission:analytics-access');

    // =============================================================
    Route::group(['middleware' => ['permission:reports.index']], function () {
        Route::get('/reports/sales', [SalesReportController::class, 'index'])->name('reports.sales.index');
        Route::get('/reports/profits', [ProfitReportController::class, 'index'])->name('reports.profits.index');
        Route::get('/reports/products', [ProductReportController::class, 'index'])->name('reports.products.index');
        Route::get('/report/finance', [ReportController::class, 'finance'])->name('report.finance');
        Route::get('/reports/refund', [RefundReportController::class, 'index'])->name('reports.refund');

        Route::get('/reports/expired', [ExpiredProductController::class, 'index'])->name('reports.expired.index');
        Route::get('/reports/expired/pdf', [ExpiredProductController::class, 'exportPdf'])->name('reports.expired.pdf');
        Route::get('/reports/expired/excel', [ExpiredProductController::class, 'exportExcel'])->name('reports.expired.excel');
        Route::delete('/reports/expired/{id}/destroy-stock', [ExpiredProductController::class, 'destroyStock'])->name('reports.expired.destroy_stock');

        // BARU: Rute Managed Reports (Laporan Otomatis via WA/Email)
        Route::get('/reports/managed', [ReportSettingController::class, 'index'])->name('report-settings.index');
        Route::post('/reports/managed', [ReportSettingController::class, 'store'])->name('report-settings.store')->middleware('permission:report_settings.update');
    });

    // =============================================================
    // 8. MODUL PENGATURAN
    // =============================================================
    Route::group(['middleware' => ['auth', 'permission:settings.index|settings.bluetooth']], function () {
        Route::get('/settings/bluetooth', fn () => Inertia::render('Dashboard/Settings/BluetoothPairing'))->name('settings.bluetooth');
    });

    Route::middleware('permission:settings.index|payment-settlements-access')->group(function () {
        Route::get('/settings/payment-settlements', [PaymentSettlementController::class, 'index'])
            ->name('settings.payment-settlements.index');
        Route::post('/settings/payment-settlements/reconcile', [PaymentSettlementController::class, 'reconcilePending'])
            ->name('settings.payment-settlements.reconcile');
        Route::post('/settings/payment-settlements/{settlement}/reconcile', [PaymentSettlementController::class, 'reconcile'])
            ->name('settings.payment-settlements.reconcile-one');
    });

    // Rule loyalty dipindahkan ke halaman Manajemen Diskon.
    Route::middleware('permission:settings.index')->group(function () {
        Route::get('/settings/loyalty-rules', fn () => redirect()->route('discounts.index'))
            ->name('settings.loyalty-rules.index');
    });

    Route::middleware('permission:settings.update|settings.cgos')->group(function () {
        Route::post('/discounts/loyalty-rules', [LoyaltyRuleController::class, 'store'])
            ->name('discounts.loyalty-rules.store');
        Route::put('/discounts/loyalty-rules/{loyaltyRule}', [LoyaltyRuleController::class, 'update'])
            ->name('discounts.loyalty-rules.update');
        Route::delete('/discounts/loyalty-rules/{loyaltyRule}', [LoyaltyRuleController::class, 'destroy'])
            ->name('discounts.loyalty-rules.destroy');
    });

    Route::group(['middleware' => ['permission:settings.index']], function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::get('/settings/online', [OnlineSettingController::class, 'index'])->name('online_settings.index');
        Route::post('/settings/online', [OnlineSettingController::class, 'store'])->name('online_settings.store');
        Route::get('/settings/payments', [PaymentSettingController::class, 'edit'])->name('settings.payments.edit');
        Route::put('/settings/payments', [PaymentSettingController::class, 'update'])->name('settings.payments.update');
        Route::get('/settings/receipt', [ReceiptSettingController::class, 'index'])->name('settings.receipt.index');
        Route::post('/settings/receipt', [ReceiptSettingController::class, 'update'])->name('settings.receipt.update');

        Route::resource('/discounts', DiscountController::class)->except(['show', 'edit', 'update']);
    });

    Route::middleware('permission:crm-access|crm-manage')->group(function () {
        Route::get('/crm/campaigns', [CrmCampaignController::class, 'index'])
            ->name('crm.campaigns.index');
        Route::get('/crm/campaigns/{campaign}', [CrmCampaignController::class, 'show'])
            ->name('crm.campaigns.show');
    });

    Route::middleware('permission:crm-manage')->group(function () {
        Route::post('/crm/segments', [CrmCampaignController::class, 'storeSegment'])->name('crm.segments.store');
        Route::put('/crm/segments/{segment}', [CrmCampaignController::class, 'updateSegment'])->name('crm.segments.update');
        Route::delete('/crm/segments/{segment}', [CrmCampaignController::class, 'destroySegment'])->name('crm.segments.destroy');
        Route::post('/crm/campaigns', [CrmCampaignController::class, 'storeCampaign'])->name('crm.campaigns.store');
        Route::put('/crm/campaigns/{campaign}', [CrmCampaignController::class, 'updateCampaign'])->name('crm.campaigns.update');
        Route::post('/crm/campaigns/{campaign}/run', [CrmCampaignController::class, 'run'])->name('crm.campaigns.run');
Route::post('/crm/test-send', [CrmCampaignController::class, 'testSend'])->name('crm.test-send');
        Route::delete('/crm/campaigns/{campaign}', [CrmCampaignController::class, 'destroyCampaign'])->name('crm.campaigns.destroy');
    });

    // =============================================================
    // 9. LAIN-LAIN (Customers & Shifts)
    // =============================================================
    Route::middleware('permission:customers-access|customers-create|customers-edit')->group(function () {
        Route::get('/customers/{customer}/loyalty', [CustomerController::class, 'loyalty'])->name('customers.loyalty');
        Route::get('/customers/{customer}/history', [CustomerController::class, 'getHistory'])->name('customers.history');
        Route::get('/customers/{customer}/voucher-preview', [CustomerController::class, 'voucherPreview'])->name('customers.voucher-preview');
    });
    Route::middleware('permission:customers-edit')->group(function () {
        Route::post('/customers/{customer}/loyalty/adjust', [CustomerController::class, 'adjustPoints'])->name('customers.loyalty.adjust');
        Route::post('/customers/{customer}/vouchers', [CustomerController::class, 'issueVoucher'])->name('customers.vouchers.store');
        Route::post('/customers/{customer}/crm-opt-out', [CustomerController::class, 'crmOptOut'])->name('customers.crm.opt-out');
        Route::post('/customers/{customer}/crm-opt-in', [CustomerController::class, 'crmOptIn'])->name('customers.crm.opt-in');
    });
    // CustomerController tidak punya method show(); detail diedit via edit.
    Route::resource('customers', CustomerController::class)->except('show');

    Route::group(['middleware' => ['permission:shifts.index']], function () {
        Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
        Route::post('/shifts', [ShiftController::class, 'store'])->name('shifts.store');
        Route::post('/shifts/close', [ShiftController::class, 'close'])->name('shifts.close');
        Route::put('/shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
        Route::get('/shifts/{shift}/print', [ShiftController::class, 'print'])->name('shifts.print');
    });

    // =============================================================
    // 10. MODAL USAHA (sisi ekuitas di neraca)
    // =============================================================
    Route::group(['middleware' => ['permission:capitals.index']], function () {
        Route::get('/capitals', [CapitalController::class, 'index'])->name('capitals.index');
        Route::post('/capitals', [CapitalController::class, 'store'])->name('capitals.store');
        Route::delete('/capitals/{capital}', [CapitalController::class, 'destroy'])->name('capitals.destroy');
    });
});

require __DIR__.'/auth.php';
