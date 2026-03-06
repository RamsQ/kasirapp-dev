<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\{Product, Table, Category, Discount}; 
use Illuminate\Http\Request; // Menambahkan Request untuk menangkap query takeaway
use Inertia\Inertia;

class PublicMenuController extends Controller 
{
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
            ->where(function($q) {
                $q->whereNull('start_date')
                  ->orWhere('start_date', '<=', now());
            })
            ->where(function($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now());
            })
            ->get();

        // 3. AMBIL DATA PRODUK DENGAN LOGIKA KETERSEDIAAN STOK/RESEP (Fitur Fix)
        $products = Product::with(['recipes.ingredient', 'category'])
            ->where(function ($query) {
                // Produk muncul jika stok fisik > 0 ATAU punya resep bahan baku
                $query->where('stock', '>', 0)
                      ->orHas('recipes');
            })
            ->orderBy('title')
            ->get()
            ->map(function ($product) {
                $isAvailable = true;

                // LOGIKA CEK KETERSEDIAAN BERDASARKAN STOK & RESEP
                // Jika stok fisik habis (0), sistem mengecek ketersediaan bahan baku (Recipe)
                if ($product->stock <= 0) {
                    if ($product->recipes->count() > 0) {
                        foreach ($product->recipes as $recipe) {
                            $ingredient = $recipe->ingredient;
                            
                            // Jika salah satu bahan baku tidak cukup, produk dianggap "Habis"
                            if (!$ingredient || $ingredient->stock < $recipe->qty_needed) {
                                $isAvailable = false;
                                break;
                            }
                        }
                    } else {
                        // Stok 0 dan tidak memiliki resep pengolahan
                        $isAvailable = false;
                    }
                }

                // Inject status ketersediaan ke objek produk
                $product->is_available = $isAvailable;
                
                return $product;
            });

        // 4. RENDER KE FRONTEND (Public/CustomerMenu)
        return Inertia::render('Public/CustomerMenu', [
            'products'     => $products,
            'activePromos' => $activePromos,
            'isTakeAway'   => $isTakeAway, // Flag untuk memberitahu UI bahwa ini pesanan Bawa Pulang
            'table'        => $table_id ? Table::find($table_id) : null,
            'categories'   => Category::orderBy('name')->get(),
        ]);
    }
}