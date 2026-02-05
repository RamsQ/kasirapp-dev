<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Performa Produk</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #444; padding-bottom: 10px; }
        .header h2 { margin: 0; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #f1f5f9; color: #444; padding: 10px; border: 1px solid #ccc; text-transform: uppercase; font-size: 10px; }
        td { padding: 8px; border: 1px solid #ccc; vertical-align: top; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .footer { margin-top: 30px; text-align: right; font-style: italic; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Laporan Performa Produk</h2>
        <p>Tanggal Cetak: {{ $date }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="15%">Barcode</th>
                <th>Nama Produk</th>
                <th width="15%">Kategori</th>
                <th width="10%">Stok</th>
                <th width="10%">Terjual</th>
                <th width="15%">Total Omzet</th>
            </tr>
        </thead>
        <tbody>
            @foreach($products as $index => $p)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>{{ $p->barcode }}</td>
                <td><strong>{{ $p->title }}</strong></td>
                <td>{{ $p->category->name ?? 'Umum' }}</td>
                <td class="text-center">{{ $p->stock }}</td>
                <td class="text-center">{{ $p->total_sold ?? 0 }}</td>
                <td class="text-right">Rp {{ number_format($p->total_revenue ?? 0, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Dicetak otomatis oleh Sistem Kasir - {{ now()->format('d/m/Y H:i:s') }}
    </div>
</body>
</html>