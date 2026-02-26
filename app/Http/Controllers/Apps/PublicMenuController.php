<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\{Product, Table, Category, Discount}; // Menambahkan Discount ke daftar model
use Inertia\Inertia;

class PublicMenuController extends Controller 
{
    public function index($table_id = null) 
    {
        // 1. AMBIL SEMUA PROMO YANG AKTIF SAAT INI
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

        // 2. AMBIL DATA PRODUK DENGAN LOGIKA KETERSEDIAAN STOK/RESEP (FITUR FIX)
        $products = Product::with(['recipes.ingredient'])
            ->where(function ($query) {
                // Produk muncul jika stok fisik > 0 ATAU punya resep
                $query->where('stock', '>', 0)
                      ->orHas('recipes');
            })
            ->orderBy('title')
            ->get()
            ->map(function ($product) {
                $isAvailable = true;

                // LOGIKA CEK KETERSEDIAAN
                // 1. Jika stok fisik kosong (0), cek resepnya
                if ($product->stock <= 0) {
                    if ($product->recipes->count() > 0) {
                        // Cek setiap bahan dalam resep
                        foreach ($product->recipes as $recipe) {
                            $ingredient = $recipe->ingredient;
                            
                            // Jika stok bahan di gudang lebih kecil dari kebutuhan resep
                            if (!$ingredient || $ingredient->stock < $recipe->quantity) {
                                $isAvailable = false;
                                break;
                            }
                        }
                    } else {
                        // Stok 0 dan tidak punya resep sama sekali
                        $isAvailable = false;
                    }
                }

                // Tambahkan atribut custom agar bisa dibaca di React (CustomerMenu.jsx)
                $product->is_available = $isAvailable;
                
                return $product;
            });

        // 3. RENDER KE FRONTEND
        return Inertia::render('Public/CustomerMenu', [
            'products'     => $products,
            'activePromos' => $activePromos, // Data promo dikirim ke Frontend
            'table'        => $table_id ? Table::find($table_id) : null,
            'categories'   => Category::orderBy('name')->get(),
        ]);
    }
}