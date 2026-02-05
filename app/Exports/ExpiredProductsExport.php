<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ExpiredProductsExport implements FromCollection, WithHeadings, WithMapping
{
    protected $startDate;
    protected $endDate;

    /**
     * Terima data filter tanggal dari Controller
     */
    public function __construct($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate   = $endDate;
    }

    /**
     * Ambil data produk berdasarkan range tanggal
     */
    public function collection()
    {
        return Product::whereBetween('expired_date', [$this->startDate, $this->endDate])
            ->orderBy('expired_date', 'asc')
            ->get();
    }

    /**
     * Judul kolom Excel (Baris pertama)
     */
    public function headings(): array
    {
        return [
            'Nama Produk',
            'Barcode',
            'Stok Saat Ini',
            'Satuan',
            'Tanggal Expired',
            'Sisa Hari',
        ];
    }

    /**
     * Mapping data ke dalam kolom Excel
     */
    public function map($product): array
    {
        return [
            $product->title,
            $product->barcode,
            $product->stock,
            $product->unit,
            $product->expired_date->format('d-m-Y'),
            $product->days_until_expired <= 0 ? 'KADALUARSA' : $product->days_until_expired . ' Hari',
        ];
    }
}