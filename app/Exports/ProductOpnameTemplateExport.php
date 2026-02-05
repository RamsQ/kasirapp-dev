<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ProductOpnameTemplateExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithColumnFormatting
{
    /**
    * Mengambil data produk jadi
    */
    public function collection()
    {
        return Product::where('type', 'single')
            ->select('id', 'barcode', 'title', 'unit', 'stock')
            ->get();
    }

    /**
    * Header Excel
    */
    public function headings(): array
    {
        return [
            'IDENTIFIER (BARCODE / ID)',
            'NAMA PRODUK',
            'SATUAN',
            'STOK SISTEM',
            'STOK FISIK / AKTUAL (ISI DI SINI)',
            'KETERANGAN (OPSIONAL)',
        ];
    }

    /**
    * Mapping data ke kolom Excel
    */
    public function map($product): array
    {
        return [
            $product->barcode ? $product->barcode : $product->id, 
            $product->title,
            $product->unit,
            $product->stock,
            $product->stock, // Default disamakan agar user tinggal ubah yang selisih saja
            '',
        ];
    }

    /**
    * Format Kolom A agar Barcode tidak rusak
    */
    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
        ];
    }
}