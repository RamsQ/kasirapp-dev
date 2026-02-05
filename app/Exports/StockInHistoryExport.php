<?php

namespace App\Exports;

use App\Models\StockMovement;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StockInHistoryExport implements FromCollection, WithHeadings, WithMapping
{
    public function collection()
    {
        // Ambil hanya riwayat masuk (Stock In)
        return StockMovement::with('product', 'user')
            ->where('type', 'in')
            ->latest()
            ->get();
    }

    public function headings(): array
    {
        return ['Tanggal', 'Petugas', 'Barcode', 'Nama Produk', 'Jumlah', 'Harga Modal', 'Total', 'Referensi'];
    }

    public function map($row): array
    {
        return [
            $row->created_at->format('d/m/Y H:i'),
            $row->user->name ?? '-',
            $row->product->barcode ?? '-',
            $row->product->title ?? '-',
            $row->qty,
            $row->price,
            $row->qty * $row->price,
            $row->reference
        ];
    }
}