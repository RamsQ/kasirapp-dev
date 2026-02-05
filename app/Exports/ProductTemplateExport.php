<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ProductTemplateExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithColumnFormatting
{
    /**
    * Mengambil data produk jadi
    * Menambahkan ID ke select untuk fallback jika barcode kosong
    */
    public function collection()
    {
        return Product::select('id', 'barcode', 'title', 'unit', 'stock', 'buy_price')->get();
    }

    /**
    * Header Excel
    */
    public function headings(): array
    {
        return [
            'IDENTIFIER (BARCODE / ID)', // Header lebih jelas
            'NAMA PRODUK',
            'SATUAN',
            'STOK SAAT INI',
            'HARGA BELI (LAMA)',
            'QTY MASUK (ISI DI SINI)',
        ];
    }

    /**
    * Mapping data ke kolom Excel
    * Logika Fallback: Jika barcode kosong, gunakan ID Produk
    */
    public function map($product): array
    {
        return [
            $product->barcode ? $product->barcode : $product->id, 
            $product->title,
            $product->unit,
            $product->stock,
            $product->buy_price,
            0, // Default nilai Qty Masuk untuk diisi user
        ];
    }

    /**
    * Memastikan kolom A (Identifier) dibaca sebagai TEXT
    * Menghindari angka barcode berubah menjadi format scientific (E+12)
    */
    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
        ];
    }
}