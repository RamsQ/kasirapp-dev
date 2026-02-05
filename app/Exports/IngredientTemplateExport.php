<?php

namespace App\Exports;

use App\Models\Ingredient;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class IngredientTemplateExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    /**
    * Mengambil data bahan baku
    */
    public function collection()
    {
        return Ingredient::select('id', 'name', 'unit', 'stock', 'buy_price')->get();
    }

    /**
    * Header Excel
    */
    public function headings(): array
    {
        return [
            'ID BAHAN (JANGAN DIUBAH)',
            'NAMA BAHAN BAKU',
            'SATUAN',
            'STOK SAAT INI',
            'HARGA BELI (LAMA)',
            'QTY MASUK (ISI DI SINI)',
        ];
    }

    /**
    * Mapping data ke kolom Excel
    */
    public function map($ingredient): array
    {
        return [
            'ING-' . $ingredient->id, // Memberi prefix agar sistem otomatis mengenali tipe data
            $ingredient->name,
            $ingredient->unit,
            $ingredient->stock,
            $ingredient->buy_price,
            0, // Default nilai Qty Masuk untuk diisi user
        ];
    }
}