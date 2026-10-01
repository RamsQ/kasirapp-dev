<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyRule;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Setting;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LoyaltyService
{
    private const TIERS = [
        ['name' => 'bronze', 'label' => 'Bronze', 'minimum' => 0],
        ['name' => 'silver', 'label' => 'Silver', 'minimum' => 100],
        ['name' => 'gold', 'label' => 'Gold', 'minimum' => 500],
        ['name' => 'platinum', 'label' => 'Platinum', 'minimum' => 1000],
    ];

    /**
     * Award points once for a paid, non-refunded transaction.
     */
    public function recordPaidTransaction(Transaction $transaction): void
    {
        if (! $transaction->customer_id || $transaction->payment_status !== 'paid' || $transaction->status === 'refunded') {
            return;
        }
        if (! $this->isEnabled()) {
            return;
        }

        $reference = 'transaction:'.$transaction->id.':earn';
        $baseAmount = max(0, (int) ($transaction->taxable_amount
            ?? ((int) $transaction->grand_total - (int) $transaction->tax_amount)));

        DB::transaction(function () use ($transaction, $reference, $baseAmount): void {
            $customer = Customer::whereKey($transaction->customer_id)->lockForUpdate()->first();
            if (! $customer || ! $customer->loyalty_enabled) {
                return;
            }
            if (LoyaltyPointLedger::where('reference', $reference)->exists()) {
                return;
            }

            $earning = $this->resolveEarning($transaction, $baseAmount);
            $points = $earning['points'];
            $rule = $earning['rule'];
            $balance = (int) $customer->loyalty_points + $points;
            $transaction->update([
                'loyalty_rule_id' => $rule?->id,
                'loyalty_points_awarded' => $points,
            ]);
            $lastVisit = $customer->last_visit_at;
            $transactionDate = $transaction->created_at ? Carbon::parse($transaction->created_at) : null;
            if (! $lastVisit || ($transactionDate && $transactionDate->gt($lastVisit))) {
                $lastVisit = $transactionDate ?: now();
            }
            $customer->update([
                'loyalty_points' => $balance,
                'lifetime_spend' => (int) $customer->lifetime_spend + $baseAmount,
                'loyalty_tier' => $this->tierForPoints($balance)['name'],
                'loyalty_enrolled_at' => $customer->loyalty_enrolled_at ?: now(),
                'last_visit_at' => $lastVisit,
            ]);

            LoyaltyPointLedger::create([
                'customer_id' => $customer->id,
                'transaction_id' => $transaction->id,
                'loyalty_rule_id' => $rule?->id,
                'type' => 'earn',
                'points' => $points,
                'balance_after' => $balance,
                'amount_base' => $baseAmount,
                'reference' => $reference,
                'description' => 'Poin dari transaksi '.$transaction->invoice
                    .($rule ? ' ('.$rule->name.')' : ' (aturan global)'),
            ]);
        });
    }

    /**
     * Reverse the original earn entry exactly once when a transaction is refunded.
     */
    public function reverseTransaction(Transaction $transaction): void
    {
        if (! $transaction->customer_id) {
            return;
        }

        $earn = LoyaltyPointLedger::where('transaction_id', $transaction->id)
            ->where('type', 'earn')
            ->first();
        if (! $earn) {
            return;
        }

        $reversalReference = 'transaction:'.$transaction->id.':reversal';
        if (LoyaltyPointLedger::where('reference', $reversalReference)->exists()) {
            return;
        }

        DB::transaction(function () use ($transaction, $earn, $reversalReference): void {
            $customer = Customer::whereKey($transaction->customer_id)->lockForUpdate()->first();
            if (! $customer) {
                return;
            }
            if (LoyaltyPointLedger::where('reference', $reversalReference)->exists()) {
                return;
            }

            $previousBalance = (int) $customer->loyalty_points;
            $balance = max(0, $previousBalance - (int) $earn->points);
            $lifetimeSpend = max(0, (int) $customer->lifetime_spend - (int) $earn->amount_base);
            $appliedPoints = $balance - $previousBalance;
            $customer->update([
                'loyalty_points' => $balance,
                'lifetime_spend' => $lifetimeSpend,
                'loyalty_tier' => $this->tierForPoints($balance)['name'],
                'last_visit_at' => Transaction::where('customer_id', $customer->id)
                    ->where('payment_status', 'paid')
                    ->where('status', '!=', 'refunded')
                    ->where('id', '!=', $transaction->id)
                    ->max('created_at'),
            ]);

            LoyaltyPointLedger::create([
                'customer_id' => $customer->id,
                'transaction_id' => $transaction->id,
                'type' => 'reversal',
                'points' => $appliedPoints,
                'balance_after' => $balance,
                'amount_base' => 0,
                'reference' => $reversalReference,
                'description' => 'Poin dibatalkan karena refund '.$transaction->invoice,
            ]);
        });
    }

    public function adjustPoints(Customer $customer, int $points, string $description = 'Penyesuaian manual'): LoyaltyPointLedger
    {
        if ($points === 0) {
            throw new RuntimeException('Jumlah poin harus bukan nol.');
        }

        return DB::transaction(function () use ($customer, $points, $description): LoyaltyPointLedger {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $previousBalance = (int) $locked->loyalty_points;
            $balance = max(0, $previousBalance + $points);
            $appliedPoints = $balance - $previousBalance;
            $locked->update([
                'loyalty_points' => $balance,
                'loyalty_tier' => $this->tierForPoints($balance)['name'],
                'loyalty_enrolled_at' => $locked->loyalty_enrolled_at ?: now(),
            ]);

            return LoyaltyPointLedger::create([
                'customer_id' => $locked->id,
                'type' => 'adjustment',
                'points' => $appliedPoints,
                'balance_after' => $balance,
                'amount_base' => 0,
                'reference' => 'manual:'.Str::uuid(),
                'description' => $description,
            ]);
        });
    }

    public function voucherDiscountFor(Customer $customer, ?string $code, int $amount): int
    {
        if (blank($code)) {
            return 0;
        }

        $normalised = strtoupper(trim($code));

        $voucher = CustomerVoucher::where('customer_id', $customer->id)
            ->whereRaw('UPPER(code) = ?', [$normalised])
            ->first();

        if ($voucher) {
            if (! $this->voucherIsUsable($voucher, $amount)) {
                throw new RuntimeException('Voucher pelanggan tidak valid atau sudah tidak dapat digunakan.');
            }

            return $this->calculateVoucherDiscount($voucher, $amount);
        }

        // Bukan voucher pribadi -> boleh jadi kode promo bersama (shop-wide).
        $promo = PromoCode::usable()->whereRaw('UPPER(code) = ?', [$normalised])->first();
        if (! $promo || $promo->minimumSpend() > $amount) {
            throw new RuntimeException('Voucher pelanggan tidak valid atau sudah tidak dapat digunakan.');
        }

        return $promo->discountFor($amount);
    }

    /**
     * Reserve a personal voucher for a transaction. Reservations are released
     * on failed/cancelled payments and consumed only after settlement.
     *
     * Kode promo bersama tidak punya baris "reserved" per pelanggan, sehingga
     * pemakaiannya dihitung lewat penghitung `reserved_count`/`used_count`
     * di tabel promo_codes agar kuota tidak terlampaui.
     */
    public function reserveVoucher(Customer $customer, ?string $code, int $amount, Transaction $transaction): int
    {
        if (blank($code)) {
            return 0;
        }

        $normalised = strtoupper(trim($code));

        $voucher = CustomerVoucher::where('customer_id', $customer->id)
            ->whereRaw('UPPER(code) = ?', [$normalised])
            ->lockForUpdate()
            ->first();

        if ($voucher) {
            if (! $this->voucherIsUsable($voucher, $amount, $transaction)) {
                throw new RuntimeException('Voucher pelanggan tidak valid atau sudah tidak dapat digunakan.');
            }

            $discount = $this->calculateVoucherDiscount($voucher, $amount);
            if ($discount <= 0) {
                throw new RuntimeException('Voucher tidak menghasilkan diskon.');
            }

            $voucher->update([
                'status' => 'reserved',
                'reserved_transaction_id' => $transaction->id,
                'reserved_at' => now(),
            ]);

            return $discount;
        }

        return $this->reservePromoCode($normalised, $amount);
    }

    private function reservePromoCode(string $code, int $amount): int
    {
        $promo = PromoCode::usable()->whereRaw('UPPER(code) = ?', [$code])->lockForUpdate()->first();
        if (! $promo || $promo->minimumSpend() > $amount) {
            throw new RuntimeException('Voucher pelanggan tidak valid atau sudah tidak dapat digunakan.');
        }

        $discount = $promo->discountFor($amount);
        if ($discount <= 0) {
            throw new RuntimeException('Voucher tidak menghasilkan diskon.');
        }

        // Kunci kuota saat reservasi supaya transaksi paralel tidak sama-sama
        // lolos despite sisa kuota tinggal satu.
        $promo->increment('reserved_count');

        return $discount;
    }

    /**
     * Tandai voucher terpakai dan tambahkan penghitungnya.
     *
     * `status` hanya berubah jadi `used` kalau kuotanya benar-benar habis.
     * Kalau masih ada sisa, vouchernya tetap `active` supaya bisa dipakai
     * lagi -- itulah yang membuat kelipatan bekerja.
     *
     * Pembacaan voucher dilakukan per baris, bukan sekali `update` massal
     * seperti sebelumnya, karena `status` sekarang bergantung pada hasil
     * perbandingan `used_count` dengan `usage_limit` yang berbeda-beda.
     */
    public function consumeVoucher(Transaction $transaction): void
    {
        CustomerVoucher::where('reserved_transaction_id', $transaction->id)
            ->get()
            ->each(function (CustomerVoucher $voucher) use ($transaction): void {
                $terpakai = (int) $voucher->used_count + 1;
                $batas = max(1, (int) $voucher->usage_limit);

                $voucher->update([
                    'used_count' => $terpakai,
                    'status' => $terpakai >= $batas ? 'used' : 'active',
                    'used_transaction_id' => $transaction->id,
                    'used_at' => now(),
                    'reserved_transaction_id' => null,
                    'reserved_at' => null,
                ]);
            });

        $this->settlePromoCodeUsage($transaction, consumed: true);
    }

    public function releaseVoucher(Transaction $transaction): void
    {
        CustomerVoucher::where('reserved_transaction_id', $transaction->id)
            ->update([
                'status' => 'active',
                'reserved_transaction_id' => null,
                'reserved_at' => null,
            ]);

        $this->settlePromoCodeUsage($transaction, consumed: false);
    }

    /**
     * Kode promo bersama tidak menyimpan transaksi mana pun, jadi disambungkan
     * lewat `voucher_code` yang sudah tersimpan di transaksi.
     */
    private function settlePromoCodeUsage(Transaction $transaction, bool $consumed): void
    {
        if (blank($transaction->voucher_code)) {
            return;
        }

        $promo = PromoCode::whereRaw('UPPER(code) = ?', [strtoupper(trim($transaction->voucher_code))])->first();
        if (! $promo) {
            return;
        }

        $reserved = max(0, $promo->reserved_count - 1);
        $used = $consumed ? $promo->used_count + 1 : $promo->used_count;

        PromoCode::whereKey($promo->id)->update([
            'reserved_count' => $reserved,
            'used_count' => $used,
        ]);
    }

    /**
     * Kembalikan voucher dan kuota promo setelah transaksi direfund.
     *
     * Tanpa ini, voucher pribadi tersangkut di status `reserved`/`used`
     * selamanya sehingga pelanggan tidak bisa memakainya lagi, dan kuota kode
     * promo bersama ikut hilang padahal transaksi sudah dibatalkan.
     *
     * Idempoten: pemanggilan kedua tidak mengubah apa pun.
     */
    public function reverseVoucher(Transaction $transaction): void
    {
        $voucher = CustomerVoucher::where('used_transaction_id', $transaction->id)
            ->orWhere('reserved_transaction_id', $transaction->id)
            ->first();

        /*
         * Voucher berkelipatan bisa dipakai beberapa kali, tapi
         * `used_transaction_id` hanya menyimpan transaksi TERAKHIR.
         *
         * Kalau pelanggan memakai kode 3x, lalu membatalkan transaksi kedua,
         * `used_transaction_id` menunjuk ke transaksi ketiga -- sehingga
         * pencarian di atas tidak menemukan apa pun dan penghitung pemakaian
         * tidak pernah dikembalikan. Efeknya satu pemakaian hilang permanen:
         * pelanggan membayar voucher tapi tidak bisa memakainya lagi.
         *
         * Karena itu transaksi sendiri yang menyimpan `voucher_code` dan
         * `customer_id`, jadi voucher bisa dicari ulang dari sana tanpa
         * bergantung pada kolom yang cuma menyimpan satu transaksi terakhir.
         */
        if (! $voucher && filled($transaction->voucher_code) && $transaction->customer_id) {
            $voucher = CustomerVoucher::where('customer_id', $transaction->customer_id)
                ->whereRaw('UPPER(code) = ?', [strtoupper(trim((string) $transaction->voucher_code))])
                ->first();
        }

        if ($voucher) {
            // Penghitung diturunkan ulang dari `used_transaction_id` supaya
            // tidak pernah turun di bawah nol atau naik melebihi batas, apa
            // pun urutan pemanggilannya.
            $sisa = max(0, (int) $voucher->used_count - 1);
            $batas = max(1, (int) $voucher->usage_limit);
            $sudahHabis = $voucher->used_transaction_id === $transaction->id;

            $voucher->update([
                'used_count' => $sisa,
                'status' => ($sudahHabis && $sisa < $batas) ? 'active' : $voucher->status,
                'used_transaction_id' => null,
                'used_at' => null,
                'reserved_transaction_id' => null,
                'reserved_at' => null,
            ]);
        }

        if (blank($transaction->voucher_code)) {
            return;
        }

        $promo = PromoCode::whereRaw('UPPER(code) = ?', [strtoupper(trim($transaction->voucher_code))])->first();
        if (! $promo) {
            return;
        }

        // Transaksi yang sudah lunas sebelumnya sudah menaikkan `used_count`,
        // jadi refund harus menurunkannya. Pemotongan dibatasi agar tidak
        // membuat penghitung negatif bila dipanggil berulang.
        PromoCode::whereKey($promo->id)
            ->update([
                'used_count' => max(0, $promo->used_count - 1),
                'reserved_count' => max(0, $promo->reserved_count - 1),
            ]);
    }

    public function issueVoucher(Customer $customer, array $data): CustomerVoucher
    {
        return CustomerVoucher::create([
            'customer_id' => $customer->id,
            'code' => strtoupper(trim($data['code'])),
            'name' => $data['name'],
            'type' => $data['type'],
            'value' => $data['value'],
            'min_spend' => $data['min_spend'] ?? 0,
            'expires_at' => $data['expires_at'] ?? null,
            'status' => 'active',
            // Voucher manual mengikuti aturan yang sama: sekali pakai kalau
            // tidak disebutkan. Tanpa ini, kode yang diterbitkan kasir akan
            // ikut berubah perilakunya hanya karena voucher-nya dibuat lewat
            // jalur lain.
            'usage_limit' => max(1, (int) ($data['usage_limit'] ?? 1)),
            'used_count' => 0,
        ]);
    }

    public function customerSummary(Customer $customer): array
    {
        $stats = Transaction::where('customer_id', $customer->id)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->selectRaw('COUNT(*) as total_transactions, COALESCE(SUM(COALESCE(taxable_amount, grand_total - COALESCE(tax_amount, 0))), 0) as total_spent, MAX(created_at) as last_visit')
            ->first();
        $points = (int) $customer->loyalty_points;
        $tier = $this->tierForPoints($points);
        $next = collect(self::TIERS)->first(fn ($item) => $item['minimum'] > $points);

        return [
            'tier' => $tier,
            'next_tier' => $next,
            'progress' => $next ? min(100, (int) round(($points / $next['minimum']) * 100)) : 100,
            'total_transactions' => (int) ($stats->total_transactions ?? 0),
            'total_spent' => (float) ($stats->total_spent ?? 0),
            'last_visit' => $stats->last_visit,
            'ledger' => $customer->loyaltyLedger()->latest('id')->limit(20)->get(),
            'vouchers' => $customer->vouchers()->latest('id')->limit(20)->get(),
        ];
    }

    /**
     * Resolve one winning rule for a transaction. Rules do not stack.
     * Priority is controlled by the administrator; the next tie-breakers
     * are the highest threshold, points, and newest rule.
     *
     * @return array{points:int,rule:?LoyaltyRule}
     */
    private function resolveEarning(Transaction $transaction, int $baseAmount): array
    {
        $rules = LoyaltyRule::active()
            ->where('min_spend', '<=', $baseAmount)
            ->orderByDesc('priority')
            ->orderByDesc('min_spend')
            ->orderByDesc('points')
            ->orderByDesc('id')
            ->get();

        if ($rules->isNotEmpty()) {
            $productIds = $transaction->details()
                ->pluck('product_id')
                ->filter()
                ->unique()
                ->values();
            $categoryIds = Product::whereIn('id', $productIds)
                ->pluck('category_id')
                ->filter()
                ->unique()
                ->values();

            foreach ($rules as $rule) {
                if ($rule->product_id && ! $productIds->contains($rule->product_id)) {
                    continue;
                }
                if ($rule->category_id && ! $categoryIds->contains($rule->category_id)) {
                    continue;
                }

                return ['points' => (int) $rule->points, 'rule' => $rule];
            }
        }

        return [
            'points' => intdiv($baseAmount, 100) * $this->pointsPerHundred(),
            'rule' => null,
        ];
    }

    private function isEnabled(): bool
    {
        $setting = Setting::first();

        return $setting ? (bool) $setting->loyalty_enabled : true;
    }

    private function pointsPerHundred(): int
    {
        $setting = Setting::first();

        return max(0, (int) ($setting?->loyalty_points_per_100 ?? 1));
    }

    private function tierForPoints(int $points): array
    {
        return collect(self::TIERS)
            ->sortByDesc('minimum')
            ->first(fn ($tier) => $points >= $tier['minimum']) ?? self::TIERS[0];
    }

    /**
     * @param  CustomerVoucher  $voucher
     */
    private function voucherIsUsable(CustomerVoucher $voucher, int $amount, ?Transaction $transaction = null): bool
    {
        if ($voucher->expires_at && $voucher->expires_at->isPast()) {
            return false;
        }
        if ((int) $voucher->min_spend > $amount) {
            return false;
        }

        // Batas pemakaian dicek dari penghitung, bukan dari `status`.
        //
        // Sebelumnya kelipatan tidak mungkin terjadi bukan karena ada batas,
        // tapi karena `consumeVoucher()` men-set `status = 'used'` dan baris
        // `status in [active, reserved]` di bawah menolaknya. Sekarang
        // `status` cuma akibat: voucher 3x baru jadi `used` setelah pemakaian
        // ketiganya.
        if ((int) $voucher->used_count >= max(1, (int) $voucher->usage_limit)) {
            return false;
        }

        if (! in_array($voucher->status, ['active', 'reserved', 'used'], true)) {
            return false;
        }

        // Voucher yang sudah habis kuotanya tidak boleh dipinjam ulang hanya
        // karena statusnya kelihatan `active`.
        if ($voucher->status === 'reserved'
            && ($transaction === null || $voucher->reserved_transaction_id !== $transaction->id)) {
            return false;
        }

        return true;
    }

    private function calculateVoucherDiscount(CustomerVoucher $voucher, int $amount): int
    {
        return max(0, min(
            $amount,
            $voucher->type === 'percentage'
                ? (int) round($amount * ((float) $voucher->value / 100))
                : (int) $voucher->value,
        ));
    }
}
