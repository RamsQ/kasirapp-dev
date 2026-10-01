<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\PaymentEvent;
use App\Models\PaymentSetting;
use App\Models\PaymentSettlement;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentSettlementController extends Controller
{
    public function index(Request $request, PaymentSettlementService $settlements): Response
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'paid', 'failed', 'refunded', 'unknown', 'expired'])],
            'provider' => ['nullable', Rule::in([PaymentSetting::GATEWAY_MIDTRANS, PaymentSetting::GATEWAY_XENDIT])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $filters = $request->only(['status', 'provider', 'search']);
        $settlements->ensureSettlements();
        $query = PaymentSettlement::query()
            ->with(['transaction:id,invoice,customer_name,payment_method,payment_status,grand_total,created_at'])
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($filters['provider'] ?? null, fn ($builder, $provider) => $builder->where('provider', $provider))
            ->when($filters['search'] ?? null, function ($builder, $search): void {
                $builder->where(function ($query) use ($search): void {
                    $query->where('provider_reference', 'like', '%'.$search.'%')
                        ->orWhereHas('transaction', fn ($transaction) => $transaction
                            ->where('invoice', 'like', '%'.$search.'%')
                            ->orWhere('customer_name', 'like', '%'.$search.'%'));
                });
            })
            ->latest('id');

        $summary = [
            'total' => PaymentSettlement::count(),
            'pending' => PaymentSettlement::where('status', 'pending')->count(),
            'paid' => PaymentSettlement::where('status', 'paid')->count(),
            'failed' => PaymentSettlement::where('status', 'failed')->count(),
            // Settlement yang menggantung: tidak di-retry otomatis, tapi
            // callback telat masih tetap bisa menyelesaikan.
            'expired' => PaymentSettlement::where('status', 'expired')->count(),
            'attention' => PaymentSettlement::whereNotNull('last_error')->count(),
        ];

        return Inertia::render('Dashboard/Settings/PaymentSettlements', [
            'settlements' => $query->paginate(25)->withQueryString(),
            'events' => PaymentEvent::with('transaction:id,invoice')
                ->latest('id')
                ->limit(20)
                ->get(),
            'summary' => $summary,
            'filters' => $filters,
            'statuses' => [
                ['value' => 'pending', 'label' => 'Menunggu'],
                ['value' => 'paid', 'label' => 'Settled'],
                ['value' => 'failed', 'label' => 'Gagal'],
                ['value' => 'refunded', 'label' => 'Refund'],
                ['value' => 'expired', 'label' => 'Menggantung'],
                ['value' => 'unknown', 'label' => 'Unknown'],
            ],
            'providers' => [
                ['value' => PaymentSetting::GATEWAY_MIDTRANS, 'label' => 'Midtrans'],
                ['value' => PaymentSetting::GATEWAY_XENDIT, 'label' => 'Xendit'],
            ],
        ]);
    }

    public function reconcile(PaymentSettlement $settlement, PaymentSettlementService $settlements)
    {
        try {
            $result = $settlements->reconcileOne($settlement);

            return back()->with('success', "Reconciliation {$result['status']} berhasil diproses.");
        } catch (\Throwable $exception) {
            return back()->with('error', 'Reconciliation gagal: '.$exception->getMessage());
        }
    }

    public function reconcilePending(PaymentSettlementService $settlements)
    {
        try {
            $result = $settlements->reconcilePending(100);

            return back()->with('success', sprintf(
                '%d transaksi diperiksa; %d settled, %d gagal, %d masih menunggu.',
                $result['checked'],
                $result['paid'],
                $result['failed'],
                $result['pending'],
            ));
        } catch (\Throwable $exception) {
            return back()->with('error', 'Reconciliation batch gagal: '.$exception->getMessage());
        }
    }
}
