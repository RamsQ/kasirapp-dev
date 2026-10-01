<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Setting;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class KitchenDisplayController extends Controller
{
    private const ACTIVE_KITCHEN_STATUSES = ['pending', 'preparing', 'ready'];

    private const KITCHEN_STATUSES = ['pending', 'preparing', 'ready', 'completed', 'cancelled'];

    private const PAYMENT_FILTERS = ['unpaid', 'paid', 'both'];

    public function index(): Response
    {
        $setting = Setting::query()->first();
        $enabled = (bool) ($setting?->kitchen_display_enabled);
        $paymentFilter = $this->normalizePaymentFilter($setting?->kitchen_display_payment_status);

        return Inertia::render('Dashboard/Kitchen/Index', [
            'enabled' => $enabled,
            'paymentFilter' => $paymentFilter,
            'orders' => $enabled ? $this->getOrders($paymentFilter) : [],
        ]);
    }

    public function orders(): JsonResponse
    {
        $setting = Setting::query()->first();
        $enabled = (bool) ($setting?->kitchen_display_enabled);
        $paymentFilter = $this->normalizePaymentFilter($setting?->kitchen_display_payment_status);

        return response()->json([
            'enabled' => $enabled,
            'payment_filter' => $paymentFilter,
            'orders' => $enabled ? $this->getOrders($paymentFilter) : [],
        ]);
    }

    public function updateStatus(Request $request, string $type, int $id): JsonResponse
    {
        $setting = Setting::query()->first();
        if (! $setting?->kitchen_display_enabled) {
            return response()->json(['message' => 'Kitchen Display sedang dinonaktifkan.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(self::KITCHEN_STATUSES)],
        ]);

        $model = match ($type) {
            'transaction' => Transaction::with(['details.product', 'cashier:id,name', 'customer:id,name'])->findOrFail($id),
            'hold' => Hold::with('table')->findOrFail($id),
            default => abort(404),
        };

        if ($model instanceof Transaction && $model->status === 'refunded') {
            return response()->json(['message' => 'Pesanan yang sudah direfund tidak dapat diubah.'], 422);
        }
        if ($model instanceof Hold && $model->transaction_id) {
            return response()->json(['message' => 'Pesanan sudah diproses ke pembayaran gateway.'], 422);
        }

        $currentStatus = $model->kitchen_status ?: 'pending';
        $allowedTransitions = [
            'pending' => ['preparing', 'cancelled'],
            'preparing' => ['ready', 'cancelled'],
            'ready' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];
        $nextStatus = $validated['status'];
        if ($nextStatus !== $currentStatus && ! in_array($nextStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            return response()->json(['message' => 'Status Kitchen Display tidak dapat dikembalikan ke tahap sebelumnya.'], 422);
        }

        $model->update(['kitchen_status' => $nextStatus]);
        $model->refresh();
        if ($model instanceof Transaction) {
            $model->loadMissing(['details.product', 'cashier:id,name', 'customer:id,name']);
        } else {
            $model->loadMissing('table');
        }

        return response()->json([
            'success' => true,
            'order' => $model instanceof Transaction
                ? $this->formatTransaction($model)
                : $this->formatHold($model),
        ]);
    }

    private function getOrders(string $paymentFilter): array
    {
        $transactions = $this->transactionQuery($paymentFilter)->get();
        $holds = $this->holdQuery($paymentFilter);

        return collect($transactions->map(fn (Transaction $transaction) => $this->formatTransaction($transaction)))
            ->concat($holds->map(fn (Hold $hold) => $this->formatHold($hold)))
            ->sortBy('created_at')
            ->take(100)
            ->values()
            ->all();
    }

    private function transactionQuery(string $paymentFilter)
    {
        $query = Transaction::query()
            ->with(['details.product', 'cashier:id,name', 'customer:id,name'])
            ->where('status', '!=', 'refunded')
            ->whereIn('kitchen_status', self::ACTIVE_KITCHEN_STATUSES);

        $this->applyEnabledSince($query);

        if ($paymentFilter === 'unpaid') {
            $query->where('payment_status', 'pending');
        } elseif ($paymentFilter === 'paid') {
            $query->where('payment_status', 'paid');
        } else {
            $query->whereIn('payment_status', ['pending', 'paid']);
        }

        return $query->oldest('created_at')->limit(100);
    }

    private function holdQuery(string $paymentFilter)
    {
        if ($paymentFilter === 'paid') {
            return collect();
        }

        $query = Hold::query()
            ->with('table')
            ->whereNull('transaction_id')
            ->whereIn('kitchen_status', self::ACTIVE_KITCHEN_STATUSES);

        $this->applyEnabledSince($query);

        return $query->oldest('created_at')->limit(100)->get();
    }

    /**
     * Batasi KDS hanya ke order yang dibuat sejak KDS dinyalakan.
     *
     * Tanpa batas ini, menyalakan KDS langsung menampilkan seluruh riwayat
     * order — termasuk order yang dibuat berminggu-minggu lalu saat KDS masih
     * mati, dan order yang lahir sebagai 'pending' karena Default kolomnya
     *_pending_ padahal tidak pernah masuk dapur.
     *
     * Kalau `kitchen_display_enabled_at` masih NULL — instalasi lama yang
     * belum pernah menyalakan KDS lewat UI — tidak ada batas yang dipasang.
     * Menyembunyikan order secara diam-diam di situation itu lebih buruk
     * daripada layar berantakan, dan tidak ada data yang bisa jadi acuan
     * kapan KDS mulai dipakai.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyEnabledSince($query): void
    {
        $enabledAt = Setting::query()->value('kitchen_display_enabled_at');

        if (filled($enabledAt)) {
            $query->where($query->getModel()->getTable().'.created_at', '>=', $enabledAt);
        }
    }

    private function formatTransaction(Transaction $transaction): array
    {
        return [
            'key' => 'transaction-'.$transaction->id,
            'source_type' => 'transaction',
            'source_id' => (int) $transaction->id,
            'invoice' => $transaction->invoice,
            'reference_code' => $transaction->reference_code,
            'customer_name' => $transaction->customer_name ?: ($transaction->customer?->name ?: 'Pelanggan'),
            'table_name' => $transaction->table_name ?: 'BAWA PULANG',
            'queue_number' => $transaction->queue_number,
            'payment_status' => $transaction->payment_status ?: 'pending',
            'payment_method' => $transaction->payment_method ?: 'cash',
            'kitchen_status' => $transaction->kitchen_status ?: 'pending',
            'grand_total' => (int) ($transaction->grand_total ?? 0),
            'tax_amount' => (int) ($transaction->tax_amount ?? 0),
            'created_at' => $this->toIsoDate($transaction->created_at),
            'items' => $transaction->details->map(fn ($detail): array => [
                'name' => $detail->product?->title ?: 'Produk',
                'qty' => (float) $detail->qty,
                'unit' => $detail->unit ?: ($detail->product?->unit ?: null),
                'notes' => $detail->notes,
            ])->values()->all(),
        ];
    }

    private function formatHold(Hold $hold): array
    {
        $items = collect($hold->cart_data ?: [])->map(function ($item): array {
            $item = is_array($item) ? $item : (array) $item;

            return [
                'name' => data_get($item, 'product.title')
                    ?: ($item['product_title'] ?? $item['title'] ?? 'Produk'),
                'qty' => (float) ($item['qty'] ?? 0),
                'unit' => $item['unit'] ?? null,
                'notes' => $item['notes'] ?? null,
            ];
        })->values()->all();

        return [
            'key' => 'hold-'.$hold->id,
            'source_type' => 'hold',
            'source_id' => (int) $hold->id,
            'invoice' => $hold->ref_number,
            'reference_code' => $hold->reference_code,
            'customer_name' => $hold->customer_name ?: 'Pelanggan',
            'table_name' => $hold->table?->name ?: 'BAWA PULANG',
            'queue_number' => $hold->queue_number,
            'payment_status' => 'unpaid',
            'payment_method' => 'manual',
            'kitchen_status' => $hold->kitchen_status ?: 'pending',
            'grand_total' => (int) ($hold->total ?? 0),
            'tax_amount' => (int) ($hold->tax_amount ?? 0),
            'created_at' => $this->toIsoDate($hold->created_at),
            'items' => $items,
        ];
    }

    private function normalizePaymentFilter(?string $filter): string
    {
        return in_array($filter, self::PAYMENT_FILTERS, true) ? $filter : 'both';
    }

    private function toIsoDate($value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
