<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Ingredient;
use App\Models\Recipe;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Illuminate\Support\Facades\Log;

class RecipeImport implements ToModel, WithHeadingRow, WithCalculatedFormulas
{
    protected $clearedProducts = [];

    public function model(array $row)
    {
        // Ambil data dari kolom
        $productName = isset($row['1_pilih_produk_klik_panah']) ? trim($row['1_pilih_produk_klik_panah']) : null;
        $barcode     = isset($row['2_barcode_otomatis']) ? trim($row['2_barcode_otomatis']) : null;
        $ingName     = isset($row['3_pilih_bahan_klik_panah']) ? trim($row['3_pilih_bahan_klik_panah']) : null;
        $qtyNeeded   = isset($row['4_qty']) ? (float) $row['4_qty'] : 0;

        // 1. Abaikan baris yang benar-benar kosong
        if (empty($productName) && empty($ingName)) {
            return null;
        }

        // 2. LOGIKA BACKUP: Jika Barcode (VLOOKUP) gagal terbaca, cari pakai Nama Produk
        $product = null;
        if (!empty($barcode)) {
            $product = Product::where('barcode', $barcode)->first();
        }

        if (!$product && !empty($productName)) {
            // Jika pencarian barcode gagal, cari berdasarkan Nama Produk (Kolom A)
            $product = Product::where('title', $productName)->first();
        }

        // 3. Cari Bahan Baku
        $ingredient = Ingredient::where('name', $ingName)->first();

        // 4. Validasi Kelengkapan
        if ($product && $ingredient && $qtyNeeded > 0) {
            
            // Bersihkan resep lama satu kali per produk
            if (!isset($this->clearedProducts[$product->id])) {
                Recipe::where('product_id', $product->id)->delete();
                $this->clearedProducts[$product->id] = true;
                Log::info("Membersihkan resep lama: {$product->title}");
            }

            return new Recipe([
                'product_id'    => $product->id,
                'ingredient_id' => $ingredient->id,
                'qty_needed'    => $qtyNeeded,
            ]);
        }

        // --- DEBUG LOG JIKA GAGAL ---
        if (!$product) {
            Log::error("Import Gagal: Produk [$productName] dengan Barcode [$barcode] tidak ditemukan di database.");
        }
        if (!$ingredient) {
            Log::error("Import Gagal: Bahan Baku [$ingName] tidak ditemukan di database.");
        }

        return null;
    }
}