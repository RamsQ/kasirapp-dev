<?php

namespace App\Imports;

use App\Models\Ingredient;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class IngredientsImport implements ToModel, WithHeadingRow
{
    /**
     * Mapping data dari Excel ke Model Ingredient
     * * @param array $row
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        // 1. Validasi: Jika kolom 'nama_bahan' kosong, lewati baris ini
        if (!isset($row['nama_bahan']) || $row['nama_bahan'] == null) {
            return null;
        }

        /**
         * 2. Logika Simpan/Update
         * Menggunakan updateOrCreate berdasarkan 'name'.
         * Jika nama bahan sudah ada, data lainnya (satuan, harga, stok) akan diperbarui.
         * Jika nama bahan belum ada, maka akan dibuatkan baris baru.
         */
        return Ingredient::updateOrCreate(
            [
                'name' => $row['nama_bahan'], // Kunci pencarian unik
            ],
            [
                // Langsung simpan string dari Excel, tidak perlu cari Unit ID lagi
                'unit'      => $row['satuan'] ?? 'pcs', 
                'buy_price' => $row['harga_modal'] ?? 0,
                'stock'     => $row['stok_saat_ini'] ?? 0,
                'min_stock' => $row['batas_minimum'] ?? 0,
            ]
        );
    }
}