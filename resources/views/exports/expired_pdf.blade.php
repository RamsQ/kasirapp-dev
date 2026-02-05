<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Produk Expired</title>
    <style>
        body { 
            font-family: 'Helvetica', Arial, sans-serif; 
            color: #333; 
            line-height: 1.5;
        }
        .header { 
            text-align: center; 
            margin-bottom: 20px; 
            border-bottom: 2px solid #444; 
            padding-bottom: 10px; 
        }
        .header h2 { 
            margin: 0; 
            text-transform: uppercase; 
            color: #e11d48; 
            font-size: 20px;
        }
        .header p {
            margin: 5px 0 0 0;
            font-size: 12px;
            color: #666;
        }
        .meta {
            margin-bottom: 20px;
            font-size: 12px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        th { 
            background-color: #f8fafc; 
            color: #64748b; 
            text-transform: uppercase; 
            font-size: 10px; 
            padding: 12px 8px; 
            border: 1px solid #e2e8f0; 
            letter-spacing: 0.05em;
        }
        td { 
            padding: 10px 8px; 
            border: 1px solid #e2e8f0; 
            font-size: 11px; 
            vertical-align: middle;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .status-expired { 
            color: #e11d48; 
            font-weight: bold; 
            background-color: #fff1f2;
        }
        .footer {
            margin-top: 30px;
            font-size: 10px;
            text-align: right;
            color: #94a3b8;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>Laporan Kontrol Expired Produk</h2>
        <p>Sistem Point of Sales - Laporan Inventaris</p>
    </div>

    <div class="meta">
        <strong>Periode:</strong> {{ \Carbon\Carbon::parse($startDate)->translatedFormat('d F Y') }} s/d {{ \Carbon\Carbon::parse($endDate)->translatedFormat('d F Y') }}<br>
        <strong>Dicetak pada:</strong> {{ now()->translatedFormat('d F Y, H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="35%">Nama Produk</th>
                <th width="20%">Barcode</th>
                <th width="10%">Stok</th>
                <th width="15%">Tgl Expired</th>
                <th width="15%">Sisa Waktu</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $index => $product)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="font-bold">{{ $product->title }}</td>
                <td class="text-center">{{ $product->barcode ?? '-' }}</td>
                <td class="text-center">{{ $product->stock }} {{ $product->unit }}</td>
                <td class="text-center">{{ $product->expired_date->format('d/m/Y') }}</td>
                <td class="text-center {{ $product->days_until_expired <= 0 ? 'status-expired' : '' }}">
                    @if($product->days_until_expired <= 0)
                        KADALUARSA
                    @else
                        {{ $product->days_until_expired }} Hari
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-center" style="padding: 30px;">
                    Tidak ada data produk yang ditemukan untuk periode ini.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Dicetak secara otomatis oleh sistem pada {{ now()->format('d/m/Y H:i:s') }}
    </div>
</body>
</html>