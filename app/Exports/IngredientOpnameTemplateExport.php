<?php

namespace App\Exports;

use App\Models\Ingredient;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class IngredientOpnameTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * Mengambil data bahan baku
     */
    public function collection()
    {
        return Ingredient::select('id', 'name', 'unit', 'stock')->get();
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
            'STOK SISTEM',
            'STOK FISIK / AKTUAL (ISI DI SINI)',
            'KETERANGAN (OPSIONAL)',
        ];
    }

    /**
     * Mapping data ke kolom Excel
     */
    public function map($ingredient): array
    {
        return [
            'ING-'.$ingredient->id,
            $ingredient->name,
            $ingredient->unit,
            $ingredient->stock,
            $ingredient->stock, // Default disamakan agar user tinggal ubah yang selisih saja
            '',
        ];
    }
}
