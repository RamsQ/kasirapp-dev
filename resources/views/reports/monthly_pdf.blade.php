<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: 'Helvetica', sans-serif; color: #333; }
        .header { text-align: center; border-bottom: 2px solid #444; margin-bottom: 30px; }
        .card { background: #f8f9fa; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #eee; }
        .total { font-weight: bold; color: #2c3e50; }
        .profit { color: #27ae60; font-size: 1.2em; }
    </style>
</head>
<body>
    <div class="header">
        <h1>REKAPITULASI PENJUALAN</h1>
        <p>Periode: {{ $periode }}</p>
    </div>

    <div class="card">
        <h3>💰 Ringkasan Keuangan</h3>
        <table>
            <tr><td>Total Transaksi</td><td class="total">{{ $count }} Trx</td></tr>
            <tr><td>Total Omzet</td><td class="total">Rp {{ number_format($revenue) }}</td></tr>
            <tr><td>Total Laba Kotor</td><td class="total">Rp {{ number_format($profit) }}</td></tr>
            <tr><td>Total Pengeluaran</td><td class="total" style="color: #e74c3c">- Rp {{ number_format($expense) }}</td></tr>
            <tr><td><strong>LABA BERSIH</strong></td><td class="profit"><strong>Rp {{ number_format($net_profit) }}</strong></td></tr>
        </table>
    </div>

    <h3>🔥 5 Produk Terlaris</h3>
    <table>
        <thead>
            <tr>
                <th>Peringkat</th>
                <th>Nama Produk</th>
                <th>Terjual</th>
            </tr>
        </thead>
        <tbody>
            @foreach($topProducts as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->product->title ?? 'N/A' }}</td>
                <td>{{ $item->total_qty }} pcs</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>