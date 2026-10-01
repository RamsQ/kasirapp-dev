<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerSegment;
use Illuminate\Database\Eloquent\Builder;

class CrmSegmentService
{
    /**
     * Segment nonaktif bersifat "pause": jumlah penerima tetap bisa dilihat
     * admin, tetapi tidak boleh dipakai untuk mengirim campaign.
     *
     * Status aktif selalu dibaca ulang dari database. Model segment bisa saja
     * di-load dengan kolom terpilih sebagian (mis. `id,name`), sehingga
     * mengandalkan atribut `is_active` di memori akan menghasilkan
     * "0 penerima" secara senyap.
     */
    public function isRunnable(CustomerSegment $segment): bool
    {
        if (! $segment->exists) {
            return false;
        }

        $isActive = CustomerSegment::whereKey($segment->id)->value('is_active');

        return (bool) (is_null($isActive) ? $segment->is_active : $isActive);
    }

    public function query(CustomerSegment $segment, bool $respectConsent = true, bool $onlyActive = true): Builder
    {
        $rules = $segment->rules ?? [];
        $query = Customer::query()
            ->whereNull('customers.crm_opt_out_at')
            ->when($respectConsent, fn (Builder $builder) => $builder->where('customers.marketing_opt_in', true));

        if ($onlyActive && ! $this->isRunnable($segment)) {
            // Segment nonaktif tidak boleh menghasilkan penerima.
            $query->whereRaw('1 = 0');
        }

        if (filled($rules['tiers'] ?? null)) {
            $tiers = array_values(array_filter((array) $rules['tiers']));
            if ($tiers !== []) {
                $query->whereIn('customers.loyalty_tier', $tiers);
            }
        }
        if (isset($rules['min_lifetime_spend'])) {
            $query->where('customers.lifetime_spend', '>=', max(0, (float) $rules['min_lifetime_spend']));
        }
        if (isset($rules['max_lifetime_spend'])) {
            $query->where('customers.lifetime_spend', '<=', max(0, (float) $rules['max_lifetime_spend']));
        }
        if (isset($rules['min_transactions'])) {
            $query->whereIn('customers.id', $this->validTransactionCustomerQuery()
                ->select('transactions.customer_id')
                ->groupBy('transactions.customer_id')
                ->havingRaw('COUNT(*) >= ?', [max(1, (int) $rules['min_transactions'])]));
        }
        if (isset($rules['inactive_days'])) {
            // Win-back: pernah belanja, tetapi tidak ada transaksi valid sejak cutoff.
            $cutoff = now()->subDays(max(1, (int) $rules['inactive_days']));
            $query->whereHas('transactions', fn (Builder $transactions) => $this->paidTransactionScope($transactions))
                ->whereDoesntHave('transactions', function (Builder $transactions) use ($cutoff): void {
                    $this->paidTransactionScope($transactions)->where('transactions.created_at', '>=', $cutoff);
                });
        }
        if (isset($rules['birthday_month'])) {
            $query->whereMonth('customers.birthday', (int) $rules['birthday_month']);
        }
        if (isset($rules['birthday_day'])) {
            $query->whereDay('customers.birthday', (int) $rules['birthday_day']);
        }
        if (filled($rules['product_id'] ?? null)) {
            $query->whereHas('transactions', function (Builder $transactions) use ($rules): void {
                $this->paidTransactionScope($transactions)
                    ->whereHas('details', function (Builder $details) use ($rules): void {
                        $details->where('transaction_details.product_id', (int) $rules['product_id']);
                    });
            });
        }
        if (filled($rules['category_id'] ?? null)) {
            $query->whereHas('transactions', function (Builder $transactions) use ($rules): void {
                $this->paidTransactionScope($transactions)
                    ->whereHas('details.product', function (Builder $product) use ($rules): void {
                        $product->where('products.category_id', (int) $rules['category_id']);
                    });
            });
        }

        return $query->select('customers.*');
    }

    /**
     * Jumlah penerima. Default tetap menghitung segment nonaktif supaya admin
     * bisa melihat potensinya; pengiriman memakai query() yang menghormati status aktif.
     */
    public function count(CustomerSegment $segment, bool $respectConsent = true, bool $onlyActive = false): int
    {
        return $this->query($segment, $respectConsent, $onlyActive)->count();
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function validTransactionCustomerQuery()
    {
        return \Illuminate\Support\Facades\DB::table('transactions')
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded');
    }

    private function paidTransactionScope(Builder $query, string $table = 'transactions'): Builder
    {
        return $query->where($table.'.payment_status', 'paid')
            ->where($table.'.status', '!=', 'refunded');
    }
}
