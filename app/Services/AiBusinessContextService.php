<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Membangun konteks bisnis untuk AI Business Coach.
 *
 * AI hanya boleh menjawab berdasarkan fakta yang dikirim di sini, jadi semua
 * angka harus berasal dari FinancialReportService (rumus keuangan kanonik)
 * dan hanya dari transaksi yang benar-benar lunas.
 */
class AiBusinessContextService
{
    public const PERIODS = [
        'today' => 'Hari Ini',
        '7d' => '7 Hari Terakhir',
        '30d' => '30 Hari Terakhir',
        'this_month' => 'Bulan Ini',
        'last_month' => 'Bulan Lalu',
    ];

    /**
     * Rentang tanggal untuk sebuah label periode, beserta rentang pembanding
     * periode sebelumnya agar AI bisa menyebut perubahan naik/turun.
     *
     * @return array{label:string,start:CarbonImmutable,end:CarbonImmutable,previous_start:CarbonImmutable,previous_end:CarbonImmutable}
     */
    public function range(string $period, ?string $from = null, ?string $to = null): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            '7d' => $this->simple($now->subDays(6)->startOfDay(), $now->endOfDay(), $now->subDays(13)->startOfDay(), $now->subDays(7)->endOfDay()),
            '30d' => $this->simple($now->subDays(29)->startOfDay(), $now->endOfDay(), $now->subDays(59)->startOfDay(), $now->subDays(30)->endOfDay()),
            'this_month' => $this->simple($now->startOfMonth(), $now->endOfMonth(), $now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()),
            'last_month' => $this->simple($now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth(), $now->subMonths(2)->startOfMonth(), $now->subMonths(2)->endOfMonth()),
            'custom' => $this->custom($from, $to, $now),
            default => $this->simple($now->startOfDay(), $now->endOfDay(), $now->subDay()->startOfDay(), $now->subDay()->endOfDay()),
        };
    }

    public function isValidPeriod(?string $period): bool
    {
        return in_array($period, array_keys(self::PERIODS), true) || $period === 'custom';
    }

    /**
     * @return array{period:string,label:string,from:CarbonImmutable,to:CarbonImmutable}
     */
    private function simple(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $prevStart, CarbonImmutable $prevEnd): array
    {
        return [
            'label' => $this->labelFor($start, $end),
            'from' => $start,
            'to' => $end,
            'previous_start' => $prevStart,
            'previous_end' => $prevEnd,
        ];
    }

    private function custom(?string $from, ?string $to, CarbonImmutable $now): array
    {
        $start = $from ? CarbonImmutable::parse($from)->startOfDay() : $now->subDays(29)->startOfDay();
        $end = $to ? CarbonImmutable::parse($to)->endOfDay() : $now->endOfDay();
        $days = max(1, $start->diffInDays($end) + 1);

        return $this->simple(
            $start,
            $end,
            $start->subDays($days),
            $start->subDay()->endOfDay(),
        );
    }

    private function labelFor(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->format('d M Y').' - '.$end->format('d M Y');
    }

    /**
     * Ringkasan satu periode lengkap dengan pembanding periode sebelumnya.
     */
    public function build(string $period, ?string $from = null, ?string $to = null): array
    {
        $range = $this->range($period, $from, $to);

        $current = $this->financials($range['from'], $range['to']);
        $previous = $this->financials($range['previous_start'], $range['previous_end']);

        return [
            'period' => $period,
            'label' => $range['label'],
            'previous_label' => $range['previous_start']->format('d M Y').' - '.$range['previous_end']->format('d M Y'),
            'current' => $current,
            'previous' => $previous,
            'top_products' => $this->topProducts($range['from'], $range['to']),
            'low_stock' => $this->lowStock(),
            'daily' => $this->dailySeries($range['from'], $range['to']),
        ];
    }

    private function financials(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $summary = app(FinancialReportService::class)->summary(
            Transaction::whereBetween('created_at', [$from, $to]),
            Expense::whereBetween('date', [$from->toDateTime(), $to->toDateTime()])
                ->where('category', '!=', 'Pelunasan Hutang')
        );

        return [
            'net_revenue' => (float) $summary['net_revenue'],
            'billed_revenue' => (float) $summary['billed_revenue'],
            'tax_collected' => (float) $summary['tax_collected'],
            'hpp' => (float) $summary['hpp'],
            'gross_profit' => (float) $summary['gross_profit_before_platform'],
            'operating_expenses' => (float) $summary['operating_expenses'],
            'net_profit' => (float) $summary['net_profit'],
            'transaction_count' => (int) $summary['transaction_count'],
        ];
    }

    private function topProducts(CarbonImmutable $from, CarbonImmutable $to)
    {
        return TransactionDetail::query()
            ->whereHas('transaction', function (Builder $query) use ($from, $to): void {
                $query->whereBetween('created_at', [$from, $to])
                    ->where('payment_status', 'paid')
                    ->where('status', '!=', 'refunded');
            })
            ->select('product_id')
            ->selectRaw('SUM(qty) AS total_qty, SUM(price) AS total_sales')
            ->with('product:id,title')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get()
            ->map(function (TransactionDetail $detail): array {
                return [
                    'name' => $detail->product?->title ?? 'Produk terhapus',
                    'qty' => (float) $detail->total_qty,
                    'sales' => (float) $detail->total_sales,
                ];
            });
    }

    private function lowStock(int $threshold = 5)
    {
        return Product::where('stock', '<=', $threshold)
            ->orderBy('stock')
            ->limit(10)
            ->get(['id', 'title', 'stock'])
            ->map(fn (Product $product): array => [
                'name' => $product->title,
                'stock' => (float) $product->stock,
            ]);
    }

    /**
     * Deret harian untuk periode panjang supaya AI bisa membaca tren,
     * bukan cuma total. Dibatasi 31 titik agar prompt tidak membludak.
     */
    private function dailySeries(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $from->diffInDays($to) + 1;
        if ($days < 2 || $days > 31) {
            return [];
        }

        $rows = Transaction::whereBetween('created_at', [$from, $to])
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->get(['grand_total', 'created_at']);

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->addDays($i);
            $series[$day->format('Y-m-d')] = 0.0;
        }

        foreach ($rows as $row) {
            $key = CarbonImmutable::parse($row->created_at)->format('Y-m-d');
            if (array_key_exists($key, $series)) {
                $series[$key] += (float) $row->grand_total;
            }
        }

        return $series;
    }

    public function rupiah(float $value): string
    {
        return 'Rp '.number_format($value, 0, ',', '.');
    }

    /**
     * Persentase perubahan, atau null bila periode sebelumnya nol.
     */
    public function changePercent(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.000001) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    public function direction(?float $percent): string
    {
        return match (true) {
            $percent === null => 'tidak ada pembanding',
            $percent > 0 => 'naik',
            $percent < 0 => 'turun',
            default => 'tetap',
        };
    }
}
