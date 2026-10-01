<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\ReceiptSetting;
use App\Models\Transaction;
use App\Services\Payments\HoldPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Struk digital untuk pelanggan pesanan menu QR.
 *
 * BUKAN struk resmi. Ini tampilan supaya pelanggan punya bukti di HP-nya
 * sendiri tanpa harusewel struk kertas, dan supaya kasir tahu pembayaran
 * seperti apa yang sudah dilFavoriteet pelanggan.
 *
 * KENAPA BUTUH SIGNATURE:
 * Struk ini terbuka tanpa login. Kalau URL-nya cuma `ref_number`, anyone bisa
 * menebak `QR-0001`, `QR-0002`, dst dan melihat pesanan orang lain beserta
 * nomornya. `ref_number` dipilih CUSTOMER (maksimal 4 karakter, pilihan
 * pelanggan) dan `reference_code` juga cuma 4 digit, jadi keduanya tidak
 * boleh dianggap rahasia. Yang menjadi bukti kebenaran adalah signature di
 * URL, yang hanya bisa dibuat server.
 *
 * YANG SENGAJA TIDAK DIKIRIM:
 *  - HPP / buy_price batch: itu datainternal usaha.
 *  - payment_reference dari gateway.
 *  - Nama kasir, shift, dan catatan internal.
 *  - Pesanan lain milik pelanggan yang sama.
 */
class PublicMenuReceiptController extends Controller
{
    public function __construct(protected HoldPaymentService $payments) {}

    public function show(Request $request, string $ref)
    {
        $receiptSetting = ReceiptSetting::first();
        [$hold, $transaction] = $this->resolve($ref);

        if ($hold) {
            $transaction = $transaction
                ?? ($hold->transaction_id
                    ? Transaction::with(['details.product', 'details.product_unit'])->find($hold->transaction_id)
                    : null);
        }

        // Kalau QR sudah lewat masa berlaku, tandai sekarang supaya pelanggan
        // diarahkan ke kasir, bukan menunggu selamanya di halaman ini.
        if ($hold?->payment_status === 'pending' && $hold->payment_expires_at?->isPast()) {
            $this->payments->markFailed($hold, 'Masa berlaku QR habis.', 'expired');
            $hold->refresh();
        }

        $page = Inertia::render('Public/Receipt', [
            'receipt' => [
                'ref_number' => $ref,
                'queue_number' => $hold?->queue_number ?: $transaction?->queue_number,
                'customer_name' => $this->customerName($hold, $transaction),
                'created_at' => optional($hold?->created_at ?: $transaction?->created_at)->toIso8601String(),
                'order_type' => ($hold?->table_id || $transaction?->table_id) ? 'dine_in' : 'takeaway',
                'table_name' => $hold?->table?->name,
                'items' => $this->items($hold, $transaction),
                'totals' => $this->totals($hold, $transaction),

                'payment' => [
                    'method' => $this->paymentMethod($hold, $transaction),
                    'status' => $this->paymentStatus($hold, $transaction),
                    'paid_amount' => $this->paidAmount($hold, $transaction),
                    'paid_at' => ($hold?->paid_at ?: $transaction?->paid_at)?->toIso8601String(),
                    'expires_at' => $hold?->payment_expires_at?->toIso8601String(),
                ],
            ],
            'store' => [
                'name' => $receiptSetting?->store_name ?? config('app.name'),
                'address' => $receiptSetting?->store_address,
                'phone' => $receiptSetting?->store_phone,
                'footer' => $receiptSetting?->store_footer,
                'logo' => $receiptSetting?->store_logo
                    ? Storage::url('receipts/'.$receiptSetting->store_logo)
                    : null,
            ],
            'printedAt' => now()->toIso8601String(),
        ]);

        // Struk ini bisa dibuka di perangkat bersama, jadi tidak boleh
        // disimpan di cache browser atau proxy di tengah jalan. Isinya juga
        // berubah dari "menunggu pembayaran" jadi "lunas", jadi cache akan
        // menampilkan informasi yang sudah basi.
        return $page->toResponse($request)->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Cari pesanan dari `ref`.
     *
     * Hold dihapus begitu kasir menagih, tapi URL struk digital sudah di tangan
     * pelanggan dan harus terus hidup. Karena itu, kalau Hold sudah tidak ada,
     * struk diambil dari transaksi resmi lewat `menu_ref`.
     *
     * `menu_ref` dipakai, bukan `reference_code`, karena `reference_code` juga
     * diisi untuk transaksi kasir biasa (acak). Kalau pencarian memakai itu,
     * `QR-1234` bisa cocok dengan struk orang lain.
     *
     * @return array{0: ?Hold, 1: ?Transaction}
     */
    private function resolve(string $ref): array
    {
        $hold = Hold::with('table')->where('ref_number', $ref)->first();

        if ($hold) {
            return [$hold, null];
        }

        $transaction = Transaction::with(['details.product', 'details.product_unit'])
            ->where('menu_ref', $ref)
            ->first();

        if (! $transaction) {
            abort(404);
        }

        return [null, $transaction];
    }

    /**
     * Nama yang tampil: bagian sebelum "#" saja, karena kode unik di belakang
     * tidak perlu dilihat pelanggan dan bisa dipakai menebak pesanan lain.
     */
    private function customerName(?Hold $hold, ?Transaction $transaction): string
    {
        $name = (string) ($hold?->customer_name ?: $transaction?->customer_name ?: '');
        $parts = explode('#', $name);

        return trim($parts[0]) ?: 'Pelanggan';
    }

    private function paymentMethod(?Hold $hold, ?Transaction $transaction): ?string
    {
        return $hold?->payment_method
            ?: $hold?->requested_payment_method
            ?: $transaction?->payment_method;
    }

    private function paymentStatus(?Hold $hold, ?Transaction $transaction): string
    {
        // Transaksi resmi selalu otoritatif soal pembayaran. Hold dipakai hanya
        // kalau transaksi belum ada, jadi pelanggan bisa melihat status
        // sebelum dan sesudah penagihan tanpa URL yang berbeda.
        if ($transaction) {
            return (string) $transaction->payment_status;
        }

        return (string) ($hold?->payment_status ?: 'unpaid');
    }

    private function paidAmount(?Hold $hold, ?Transaction $transaction): ?float
    {
        if ($hold?->paid_amount !== null) {
            return (float) $hold->paid_amount;
        }

        if ($transaction && (string) $transaction->payment_status === 'paid') {
            return (float) $transaction->grand_total;
        }

        return null;
    }

    /**
     * @return array<int, array{title:string, qty:float, price:float, total:float, notes:string|null, unit:string|null}>
     */
    private function items(?Hold $hold, ?Transaction $transaction): array
    {
        // Sumber utama: detail transaksi resmi. `cart_data` hanya dipakai
        // selama pesanan belum ditagih, dan isinya sudah harga yang sama
        // dengan yang dihitung server.
        if ($transaction && $transaction->details->isNotEmpty()) {
            return $transaction->details->map(fn ($detail) => [
                'title' => $detail->product?->title ?? 'Item',
                'qty' => (float) $detail->qty,
                'price' => (float) $detail->price,
                'total' => round((float) $detail->price * (float) $detail->qty, 2),
                'notes' => $detail->notes,
                'unit' => $detail->unit,
            ])->values()->all();
        }

        return collect((array) ($hold?->cart_data ?? []))->map(function ($item) {
            $qty = (float) ($item['qty'] ?? 1);
            $price = (float) ($item['current_unit_price'] ?? $item['sell_price'] ?? 0);

            return [
                'title' => (string) ($item['product_title'] ?? 'Item'),
                'qty' => $qty,
                'price' => $price,
                'total' => round($qty * $price, 2),
                'notes' => $item['notes'] ?? null,
                'unit' => null,
            ];
        })->values()->all();
    }

    /**
     * @return array{subtotal:float, discount:float, tax:float, tax_rate:float|null, total:float, paid:bool}
     */
    private function totals(?Hold $hold, ?Transaction $transaction): array
    {
        $total = $transaction
            ? (float) $transaction->grand_total
            : (float) ($hold?->total ?? 0);

        $tax = $transaction
            ? (float) $transaction->tax_amount
            : (float) ($hold?->tax_amount ?? 0);

        $taxable = $transaction
            ? (float) $transaction->taxable_amount
            : (float) ($hold?->taxable_amount ?? 0);

        return [
            'subtotal' => round($taxable + (float) ($transaction?->discount ?? 0), 2),
            'discount' => (float) ($transaction?->discount ?? 0),
            'tax' => $tax,
            'tax_rate' => $transaction ? $transaction->tax_rate : $hold?->tax_rate,
            'total' => $total,
            // Pelanggan hanya boleh melihat "lunas" kalau pembayarannya
            // benar-benar tercatat di server. Status di sini tidak pernah
            // berasal dari apa yang diklik di halaman menu.
            'paid' => $this->paymentStatus($hold, $transaction) === 'paid',
        ];
    }
}
