<?php

use App\Http\Controllers\Apps\CategoryController;
use App\Http\Controllers\Apps\CustomerController;
use App\Http\Controllers\Apps\PaymentSettingController;
use App\Http\Controllers\Apps\ProductController;
use App\Http\Controllers\Apps\TransactionController;
use App\Http\Controllers\Apps\ProductReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Apps\ProfileController; 
use App\Http\Controllers\Reports\ProfitReportController;
use App\Http\Controllers\Reports\SalesReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ShiftController; 
use App\Http\Controllers\Apps\StockOpnameController;
use App\Http\Controllers\Apps\StockInController; 
use App\Http\Controllers\Apps\ExpiredProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\Apps\ReceiptSettingController;
use App\Http\Controllers\Apps\DiscountController;
use App\Http\Controllers\Apps\SettingController; 
use App\Http\Controllers\Apps\TableController;
use App\Http\Controllers\Auth\FaceAuthController;
use App\Http\Controllers\Apps\IngredientController;
use App\Http\Controllers\Apps\UnitController;
use App\Http\Controllers\Reports\RefundReportController;
use App\Http\Controllers\Apps\RecipeController; 
use App\Http\Controllers\Apps\PublicMenuController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// =============================================================
// RUTE PUBLIK
// =============================================================
Route::get('/', function () {
    return Inertia::render('Auth/Login', [
        'canLogin'       => Route::has('login'),
        'canRegister'    => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion'     => PHP_VERSION,
    ]);
});

// --- SELF-ORDERING & AUTH ---
Route::get('/menu/{table_id?}', [PublicMenuController::class, 'index'])->name('public.menu');
Route::post('/menu/order', [TransactionController::class, 'customerOrder'])->name('public.menu.order');
Route::post('/face-auth/fetch-user', [FaceAuthController::class, 'fetchUser'])->name('face.fetch');
Route::post('/face-auth/login', [FaceAuthController::class, 'login'])->name('face.login');
Route::get('/share/invoice/{invoice}', [TransactionController::class, 'shareInvoice'])->name('transactions.share');

// =============================================================
// RUTE PRIVATE (Dashboard)
// =============================================================
Route::group(['prefix' => 'dashboard', 'middleware' => ['auth']], function () {
    
    // [0] DASHBOARD UTAMA
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // [0.1] PROFIL & DANGER ZONE
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.edit');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update'); 
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::post('/profile/face-update', [ProfileController::class, 'updateFace'])->name('profile.face.update');
    Route::post('/face-registration-alt', [ProfileController::class, 'updateFace'])->name('face.register');

    Route::delete('/profile/reset-system', [TransactionController::class, 'destroyAll'])
        ->name('system.reset')
        ->middleware(['role:super-admin']);

    // [0.2] SHIFT KASIR
    Route::group(['middleware' => ['role_or_permission:super-admin|shifts-access|transactions-access']], function () {
        Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index'); 
        Route::post('/shifts', [ShiftController::class, 'store'])->name('shifts.store');
        Route::post('/shifts/close', [ShiftController::class, 'close'])->name('shifts.close');
        Route::put('/shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
        Route::get('/shifts/{shift}/print', [ShiftController::class, 'print'])->name('shifts.print');
    });

    // [1] PENGATURAN SISTEM (ADMIN/OWNER)
    Route::group(['middleware' => ['role_or_permission:super-admin|settings.index']], function () {
        Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::resource('/roles', RoleController::class)->except(['create', 'edit', 'show']);
        Route::resource('/users', UserController::class)->except('show');

        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::get('/settings/payments', [PaymentSettingController::class, 'edit'])->name('settings.payments.edit');
        Route::put('/settings/payments', [PaymentSettingController::class, 'update'])->name('settings.payments.update');
        Route::get('/settings/receipt', [ReceiptSettingController::class, 'index'])->name('settings.receipt.index');
        Route::post('/settings/receipt', [ReceiptSettingController::class, 'update'])->name('settings.receipt.update');
        Route::get('/settings/bluetooth', fn() => Inertia::render('Dashboard/Settings/BluetoothPairing'))->name('settings.bluetooth');

        Route::resource('/discounts', DiscountController::class)->except(['show', 'edit', 'update']);
        Route::resource('/units', UnitController::class)->except(['show', 'create', 'edit']);
    });

    // [2] OPERASIONAL KASIR
    Route::group(['middleware' => ['role_or_permission:super-admin|transactions-access']], function () {
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::post('/transactions/addToCart', [TransactionController::class, 'addToCart'])->name('transactions.addToCart');
        Route::delete('/transactions/{cart_id}/destroyCart', [TransactionController::class, 'destroyCart'])->name('transactions.destroyCart');
        Route::patch('/transactions/{cart_id}/updateCart', [TransactionController::class, 'updateCart'])->name('transactions.updateCart');
        Route::post('/transactions/store', [TransactionController::class, 'store'])->name('transactions.store');
        Route::get('/transactions/history', [TransactionController::class, 'history'])->name('transactions.history');
        Route::post('/transactions/expense', [TransactionController::class, 'storeExpense'])->name('transactions.expense');
        Route::post('/transactions/hold', [TransactionController::class, 'holdCart'])->name('transactions.hold');
        Route::post('/transactions/resume/{holdId}', [TransactionController::class, 'resumeCart'])->name('transactions.resume');
        Route::post('/transactions/move-table/{holdId}', [TransactionController::class, 'moveTable'])->name('transactions.move_table');
        Route::post('/transactions/merge-table', [TransactionController::class, 'mergeTable'])->name('transactions.merge_table');
        Route::delete('/holds/{id}', [TransactionController::class, 'destroyHold'])->name('holds.destroy');
        Route::get('/transactions/{invoice}/print', [TransactionController::class, 'print'])->name('transactions.print');
        Route::get('/transactions/bill/{id}', [TransactionController::class, 'printBill'])->name('transactions.bill'); 
        Route::post('/transactions/{transaction}/refund', [TransactionController::class, 'refund'])->name('transactions.refund');
    });

    // [3] INVENTORY & MASTER DATA
    Route::group(['middleware' => ['role_or_permission:super-admin|products-access|ingredients.index']], function () {
        Route::get('/tables/print-qr', [TableController::class, 'printQr'])->name('tables.printQr');
        Route::resource('/tables', TableController::class)->except(['create', 'edit', 'show']);
        
        Route::resource('categories', CategoryController::class);

        // --- PRODUK (Fixed: Ziggy Route Template) ---
        Route::get('/products/template', [ProductController::class, 'template'])->name('products.template'); 
        Route::post('/products/import', [ProductController::class, 'import'])->name('products.import');
        Route::post('/products/bulk-destroy', [ProductController::class, 'bulkDestroy'])->name('products.bulk_destroy');
        Route::resource('products', ProductController::class);

        // --- BAHAN BAKU ---
        Route::get('/ingredients/template', [IngredientController::class, 'template'])->name('ingredients.template');
        Route::post('/ingredients/import', [IngredientController::class, 'import'])->name('ingredients.import');
        Route::resource('/ingredients', IngredientController::class)->except(['create', 'edit', 'show']);

        // --- RESEP ---
        Route::get('/recipes/template', [RecipeController::class, 'template'])->name('recipes.template');
        Route::post('/recipes/import', [RecipeController::class, 'import'])->name('recipes.import');
        Route::get('/recipes', [RecipeController::class, 'index'])->name('recipes.index');
        Route::post('/recipes', [RecipeController::class, 'store'])->name('recipes.store');
        Route::delete('/recipes/{id}', [RecipeController::class, 'destroy'])->name('recipes.destroy');
        Route::post('/recipes/sync-all', [RecipeController::class, 'syncAll'])->name('recipes.sync_all');

        // --- STOK ---
        Route::prefix('stock-in')->group(function() {
            Route::get('/', [StockInController::class, 'index'])->name('stock_in.index');
            Route::post('/', [StockInController::class, 'store'])->name('stock_in.store');
            Route::get('/export', [StockInController::class, 'export'])->name('stock_in.export');
            Route::post('/parse', [StockInController::class, 'parseExcel'])->name('stock_in.parse_excel');
            Route::get('/template-product', [StockInController::class, 'exportProductTemplate'])->name('stock_in.template_product');
            Route::get('/template-ingredient', [StockInController::class, 'exportIngredientTemplate'])->name('stock_in.template_ingredient');
        });

        Route::get('/stock-opnames', [StockOpnameController::class, 'index'])->name('stock_opnames.index');
        Route::post('/stock-opnames', [StockOpnameController::class, 'store'])->name('stock_opnames.store');
        Route::get('/stock-opnames/template-product', [StockOpnameController::class, 'exportProductTemplate'])->name('stock_opnames.template_product');
        Route::get('/stock-opnames/template-ingredient', [StockOpnameController::class, 'exportIngredientTemplate'])->name('stock_opnames.template_ingredient');
    });
    
    Route::resource('customers', CustomerController::class);

    // [4] REPORTS & FINANCE
    Route::group(['middleware' => ['role_or_permission:super-admin|reports-access']], function () {
        Route::get('/reports/sales', [SalesReportController::class, 'index'])->name('reports.sales.index');
        Route::get('/reports/profits', [ProfitReportController::class, 'index'])->name('reports.profits.index');
        Route::get('/reports/products', [ProductReportController::class, 'index'])->name('reports.products.index');
        Route::get('/report/finance', [ReportController::class, 'finance'])->name('report.finance');
        Route::get('/reports/refund', [RefundReportController::class, 'index'])->name('reports.refund');
        
        // Kontrol Expired
        Route::get('/reports/expired', [ExpiredProductController::class, 'index'])->name('reports.expired.index');
        Route::get('/reports/expired/pdf', [ExpiredProductController::class, 'exportPdf'])->name('reports.expired.pdf');
        Route::get('/reports/expired/excel', [ExpiredProductController::class, 'exportExcel'])->name('reports.expired.excel');
        Route::delete('/reports/expired/{id}/destroy-stock', [ExpiredProductController::class, 'destroyStock'])->name('reports.expired.destroy_stock');
    });

    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');

});

require __DIR__ . '/auth.php';