<?php

use App\Http\Controllers\Apps\{
    CategoryController, CustomerController, PaymentSettingController, 
    ProductController, TransactionController, ProductReportController,
    ProfileController, StockOpnameController, StockInController, 
    ExpiredProductController, ReceiptSettingController, DiscountController,
    SettingController, TableController, IngredientController, 
    // UnitController, // Dinonaktifkan sementara karena file tidak ditemukan
    RecipeController, PublicMenuController, OnlineSettingController
};
use App\Http\Controllers\{
    DashboardController, PermissionController, RoleController, 
    UserController, ShiftController, ReportController, ExpenseController,
    AiController
};
use App\Http\Controllers\Reports\{ProfitReportController, SalesReportController, RefundReportController};
use App\Http\Controllers\Auth\FaceAuthController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes - Mangkujagad PWA
|--------------------------------------------------------------------------
*/

// =============================================================
// 1. RUTE PUBLIK & SELF-ORDERING (Tanpa Login / Guest)
// =============================================================
Route::get('/', function () {
    return Inertia::render('Auth/Login', [
        'canLogin'       => Route::has('login'),
        'canRegister'    => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion'     => PHP_VERSION,
    ]);
});

// Fitur QR Menu Pelanggan (Wajib di luar group 'auth')
Route::prefix('menu')->group(function () {
    Route::get('/{table_id?}', [PublicMenuController::class, 'index'])->name('public.menu');
    Route::post('/order', [TransactionController::class, 'customerOrder'])->name('public.menu.order');
});

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
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update'); 
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::post('/profile/face-update', [ProfileController::class, 'updateFace'])->name('profile.face.update');
    Route::post('/face-registration-alt', [ProfileController::class, 'updateFace'])->name('face.register');

    // [0.2] AI BUSINESS COACH
    Route::get('/ai-coach', [AiController::class, 'index'])->name('ai.index');
    Route::post('/ai-coach/chat', [AiController::class, 'chat'])->name('ai.chat');

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
    Route::resource('categories', CategoryController::class);

    // PINDAHKAN RUTE TEMPLATE KE SINI (DI ATAS RESOURCE)
    Route::get('/products/template', [ProductController::class, 'template'])->name('products.template');
    
    // BARU KEMUDIAN RESOURCE
    Route::resource('products', ProductController::class);

    Route::post('/products/import', [ProductController::class, 'import'])->name('products.import');
    Route::post('/products/bulk-destroy', [ProductController::class, 'bulkDestroy'])->name('products.bulk_destroy');
    
    Route::resource('/tables', TableController::class)->except(['create', 'edit', 'show']);
    Route::get('/tables/print-qr', [TableController::class, 'printQr'])->name('tables.printQr');
    });

    // =============================================================
    // 5. MODUL WAREHOUSE & INVENTORY
    // =============================================================
    Route::group(['middleware' => ['permission:ingredients.index']], function () {
        // --- INGREDIENTS ---
        Route::resource('/ingredients', IngredientController::class)->except(['create', 'edit', 'show']);
        Route::get('/ingredients-template', [IngredientController::class, 'template'])->name('ingredients.template');
        Route::post('/ingredients-import', [IngredientController::class, 'import'])->name('ingredients.import');

        // --- RECIPES (PENGATURAN HPP) ---
        Route::get('/recipes', [RecipeController::class, 'index'])->name('recipes.index');
        Route::post('/recipes', [RecipeController::class, 'store'])->name('recipes.store');
        Route::get('/recipes/template', [RecipeController::class, 'template'])->name('recipes.template');
        Route::post('/recipes/import', [RecipeController::class, 'import'])->name('recipes.import');
        Route::post('/recipes/sync-all', [RecipeController::class, 'syncAll'])->name('recipes.sync_all');
        Route::delete('/recipes/{id}', [RecipeController::class, 'destroy'])->name('recipes.destroy');

        // --- STOCK-IN MODULE ---
        Route::prefix('stock-in')->group(function() {
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
    Route::group(['middleware' => ['permission:transactions.index']], function () {
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::post('/transactions/store', [TransactionController::class, 'store'])->name('transactions.store');
        Route::get('/transactions/history', [TransactionController::class, 'history'])->name('transactions.history');
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
            
        Route::get('/transactions/bill/{id}', [TransactionController::class, 'printBill'])->name('transactions.bill');
    });

    // =============================================================
    // 7. MODUL LAPORAN & KEUANGAN
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
    });

    // =============================================================
    // 8. MODUL PENGATURAN
    // =============================================================
    Route::group(['middleware' => ['permission:settings.index']], function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::get('/settings/online', [OnlineSettingController::class, 'index'])->name('online_settings.index');
        Route::post('/settings/online', [OnlineSettingController::class, 'store'])->name('online_settings.store');
        Route::get('/settings/payments', [PaymentSettingController::class, 'edit'])->name('settings.payments.edit');
        Route::put('/settings/payments', [PaymentSettingController::class, 'update'])->name('settings.payments.update');
        Route::get('/settings/receipt', [ReceiptSettingController::class, 'index'])->name('settings.receipt.index');
        Route::post('/settings/receipt', [ReceiptSettingController::class, 'update'])->name('settings.receipt.update');
        Route::get('/settings/bluetooth', fn() => Inertia::render('Dashboard/Settings/BluetoothPairing'))->name('settings.bluetooth');
        
        Route::resource('/discounts', DiscountController::class)->except(['show', 'edit', 'update']);
        
        // Dinonaktifkan karena menyebabkan ReflectionException (File tidak ada)
        // Route::resource('/units', UnitController::class)->except(['show', 'create', 'edit']);
    });

    // =============================================================
    // 9. LAIN-LAIN (Customers & Shifts)
    // =============================================================
    Route::resource('customers', CustomerController::class);

    Route::group(['middleware' => ['permission:shifts.index']], function () {
        Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index'); 
        Route::post('/shifts', [ShiftController::class, 'store'])->name('shifts.store');
        Route::post('/shifts/close', [ShiftController::class, 'close'])->name('shifts.close');
        Route::put('/shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
        Route::get('/shifts/{shift}/print', [ShiftController::class, 'print'])->name('shifts.print');
    });
});

require __DIR__ . '/auth.php';