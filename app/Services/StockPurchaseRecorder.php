<?php

namespace App\Services;

use App\Models\Expense;
use Illuminate\Support\Collection;

/**
 * Mencatat arus kas dari pembelian stok.
 *
 * Pembelian stok adalah KONVERSI, bukan beban: kas atau hutang turun, dan
 * persediaan naik sebesar yang sama. Kalau tidak ada pencatatannya, nilai
 * persediaan di neraca naik tanpa ada sisi lain yang ikut turun, sehingga
 * selisih neraca selalu sebesar nilai stok yang dibeli.
 *
 * Yang SENGAJA tidak dilakukan di sini: mengurangi laba. Pembelian stok
 * bukan beban operasional. Kalau dipotong dari laba sekaligus dihitung
 * sebagai aset, biayanya terhitung dua kali. Biayanya baru jadi beban saat
 * barang terjual, lewat HPP.
 */
class StockPurchaseRecorder
{
    /**
     * Source expense yang menandai pembelian stok.
     *
     * FinancialReportService mengecualikannya dari beban operasional, dan
     * neraca memakainya untuk memisahkan yang dibayar kas dan yang jadi hutang.
     */
    public const SOURCE = 'Belanja Stok';

    public const CATEGORY = 'Pembelian Stok';

    public const PAID_CASH = 'cash';

    public const PAID_CREDIT = 'credit';

    /**
     * Catat satu pengeluaran kas untuk seluruh isi stock-in.
     *
     * @param  Collection<int, array{name:string, total:float}>  $lines
     */
    public function record(Collection $lines, string $paymentMethod, int $userId, ?string $date = null): ?Expense
    {
        $total = round((float) $lines->sum('total'), 2);
        if ($total <= 0) {
            return null;
        }

        $paymentMethod = $paymentMethod === self::PAID_CREDIT ? self::PAID_CREDIT : self::PAID_CASH;

        $items = $lines->map(fn ($line) => $line['name'].' ('.$this->money($line['total']).')')
            ->implode(', ');

        return Expense::create([
            'user_id' => $userId,
            'name' => 'Pembelian Stok - '.($paymentMethod === self::PAID_CREDIT ? 'Dibayar Hutang' : 'Dibayar Tunai'),
            'category' => self::CATEGORY,
            'source' => self::SOURCE,
            'payment_method' => $paymentMethod,
            'amount' => $total,
            'date' => $date ?? now()->toDateString(),
            'note' => mb_substr($items, 0, 1000),
        ]);
    }

    private function money(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
