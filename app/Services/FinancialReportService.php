<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Central definition for the financial figures used by every report.
 *
 * Tax is a pass-through liability and is therefore not part of revenue,
 * COGS, or profit. HPP is read from the immutable snapshot stored on each
 * transaction detail instead of being inferred from the profits table.
 * Debt repayments and owner capital withdrawals are financing flows, not
 * operating expenses.
 */
class FinancialReportService
{
    /**
     * Apply the common definition of a reportable transaction.
     *
     * @param  EloquentBuilder|QueryBuilder  $query
     * @return EloquentBuilder|QueryBuilder
     */
    public function validTransactions($query)
    {
        return $query
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded');
    }

    /**
     * Calculate the canonical financial summary for a transaction query.
     *
     * The query must already contain its date/user/invoice constraints. The
     * service only adds the common paid/non-refunded scope when calculating.
     *
     * @param  EloquentBuilder|QueryBuilder  $transactionQuery
     * @return array<string, float|int>
     */
    public function summary($transactionQuery, ?EloquentBuilder $expenseQuery = null): array
    {
        /** @var EloquentBuilder|QueryBuilder $transactionQuery */
        $transactionQuery = $this->validTransactions(clone $transactionQuery);
        $transactionIds = (clone $transactionQuery)->select('transactions.id');

        $billedRevenue = (float) (clone $transactionQuery)->sum('grand_total');
        $taxCollected = (float) (clone $transactionQuery)->sum(
            DB::raw('COALESCE(tax_amount, 0)')
        );
        $netRevenue = (float) (clone $transactionQuery)->sum(
            DB::raw('COALESCE(taxable_amount, grand_total - COALESCE(tax_amount, 0))')
        );
        $appExpenses = (float) (clone $transactionQuery)->sum(
            DB::raw('COALESCE(total_markup, 0) + COALESCE(total_fee, 0)')
        );

        $totalHpp = (float) DB::table('transaction_details as details')
            ->whereIn('details.transaction_id', $transactionIds)
            ->sum(DB::raw('COALESCE(details.buy_price, 0) * COALESCE(details.qty, 0)'));

        $storedProfit = (float) DB::table('profits')
            ->whereIn('transaction_id', $transactionIds)
            ->sum('total');

        // Daftar sumber yang tidak boleh memotong labanya TIDAK ditulis di
        // sini. Scope operatingExpense() di model Expense adalah satu-satunya
        // definisinya, dipakai juga oleh tren per periode di AnalyticsService,
        // supaya KPI dan grafik tidak mungkin menghitung beban beda.
        $operatingExpenses = $expenseQuery
            ? (float) (clone $expenseQuery)->operatingExpense()->sum('amount')
            : 0.0;
        $grossProfitBeforePlatform = $netRevenue - $totalHpp;
        $contributionProfit = $grossProfitBeforePlatform - $appExpenses;
        $netProfit = $contributionProfit - $operatingExpenses;

        return [
            'billed_revenue' => $billedRevenue,
            'tax_collected' => $taxCollected,
            'net_revenue' => $netRevenue,
            'app_expenses' => $appExpenses,
            'hpp' => $totalHpp,
            'gross_profit_before_platform' => $grossProfitBeforePlatform,
            'contribution_profit' => $contributionProfit,
            'operating_expenses' => $operatingExpenses,
            'net_profit' => $netProfit,
            'stored_profit' => $storedProfit,
            // A non-zero value means legacy Profit rows need reconciliation.
            'profit_variance' => $storedProfit - $contributionProfit,
            'transaction_count' => (int) (clone $transactionQuery)->count(),
            'items_sold' => (int) DB::table('transaction_details as details')
                ->whereIn('details.transaction_id', $transactionIds)
                ->sum('details.qty'),
        ];
    }
}
