<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function __construct(protected LoyaltyService $loyalty) {}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // get customers
        $customers = Customer::when(request()->search, function ($customers) {
            $search = request()->search;
            $customers = $customers->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('no_telp', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        })->latest()->paginate(15);

        $topCustomers = Customer::query()
            ->leftJoin('transactions', function ($join): void {
                $join->on('transactions.customer_id', '=', 'customers.id')
                    ->where('transactions.payment_status', 'paid')
                    ->where('transactions.status', '!=', 'refunded');
            })
            ->select([
                'customers.id',
                'customers.name',
                'customers.no_telp',
                'customers.loyalty_points',
                'customers.loyalty_tier',
            ])
            ->selectRaw('COUNT(transactions.id) as crm_transactions, COALESCE(SUM(COALESCE(transactions.taxable_amount, transactions.grand_total - COALESCE(transactions.tax_amount, 0))), 0) as crm_spend')
            ->groupBy('customers.id', 'customers.name', 'customers.no_telp', 'customers.loyalty_points', 'customers.loyalty_tier')
            ->orderByDesc('crm_spend')
            ->limit(5)
            ->get();
        $birthdayCustomers = Customer::whereNotNull('birthday')
            ->whereMonth('birthday', now()->month)
            ->whereDay('birthday', now()->day)
            ->orderBy('name')
            ->limit(5)
            ->get();

        // return inertia
        return Inertia::render('Dashboard/Customers/Index', [
            'customers' => $customers,
            'topCustomers' => $topCustomers,
            'birthdayCustomers' => $birthdayCustomers,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return Inertia::render('Dashboard/Customers/Create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        /**
         * validate
         */
        $request->validate([
            'name' => 'required|string|max:255',
            'no_telp' => 'required|unique:customers,no_telp',
            'email' => ['nullable', 'email', 'max:255'],
            'address' => 'required|string|max:1000',
            'loyalty_enabled' => ['nullable', 'boolean'],
            'birthday' => ['nullable', 'date'],
            'marketing_opt_in' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // create customer
        Customer::create([
            'name' => $request->name,
            'no_telp' => $request->no_telp,
            'email' => $request->email,
            'address' => $request->address,
            'loyalty_enabled' => $request->boolean('loyalty_enabled', true),
            'birthday' => $request->birthday,
            'marketing_opt_in' => $request->boolean('marketing_opt_in'),
            'notes' => $request->notes,
            'loyalty_enrolled_at' => now(),
        ]);

        // redirect
        return to_route('customers.index');
    }

    /**
     * Store a newly created customer via AJAX (returns JSON, no redirect)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeAjax(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'no_telp' => 'required|string|unique:customers,no_telp',
            'email' => ['nullable', 'email', 'max:255'],
            'address' => 'required|string',
            'loyalty_enabled' => ['nullable', 'boolean'],
            'birthday' => ['nullable', 'date'],
            'marketing_opt_in' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $customer = Customer::create([
                'name' => $validated['name'],
                'no_telp' => $validated['no_telp'],
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'],
                'loyalty_enabled' => $request->boolean('loyalty_enabled', true),
                'birthday' => $validated['birthday'] ?? null,
                'marketing_opt_in' => $request->boolean('marketing_opt_in'),
                'notes' => $validated['notes'] ?? null,
                'loyalty_enrolled_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pelanggan berhasil ditambahkan',
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->no_telp,
                    'address' => $customer->address,
                    'loyalty_points' => (int) $customer->loyalty_points,
                    'loyalty_tier' => $customer->tierLabel(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan pelanggan',
                'errors' => [],
            ], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Customer $customer)
    {
        return Inertia::render('Dashboard/Customers/Edit', [
            'customer' => $customer,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Customer $customer)
    {
        /**
         * validate
         */
        $request->validate([
            'name' => 'required|string|max:255',
            'no_telp' => 'required|unique:customers,no_telp,'.$customer->id,
            'email' => ['nullable', 'email', 'max:255'],
            'address' => 'required|string|max:1000',
            'loyalty_enabled' => ['nullable', 'boolean'],
            'birthday' => ['nullable', 'date'],
            'marketing_opt_in' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // update customer
        $customer->update([
            'name' => $request->name,
            'no_telp' => $request->no_telp,
            'email' => $request->email,
            'address' => $request->address,
            'loyalty_enabled' => $request->has('loyalty_enabled')
                ? $request->boolean('loyalty_enabled')
                : $customer->loyalty_enabled,
            'birthday' => $request->birthday,
            'marketing_opt_in' => $request->has('marketing_opt_in')
                ? $request->boolean('marketing_opt_in')
                : $customer->marketing_opt_in,
            'notes' => $request->notes,
        ]);

        // redirect
        return to_route('customers.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        // find customer by ID
        $customer = Customer::findOrFail($id);

        // delete customer
        $customer->delete();

        // redirect
        return back();
    }

    public function loyalty(Customer $customer)
    {
        $customer->refresh();

        return Inertia::render('Dashboard/Customers/Loyalty', [
            'customer' => $customer,
            'summary' => $this->loyalty->customerSummary($customer),
            'canManageLoyalty' => auth()->user()->can('customers-edit'),
        ]);
    }

    public function voucherPreview(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $discount = $this->loyalty->voucherDiscountFor(
                $customer,
                $validated['code'],
                (int) $validated['amount'],
            );

            return response()->json([
                'success' => true,
                'discount' => $discount,
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Voucher tidak dapat diproses.',
            ], 422);
        }
    }

    public function adjustPoints(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'points' => ['required', 'integer', 'not_in:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->loyalty->adjustPoints(
                $customer,
                (int) $validated['points'],
                $validated['description'] ?: 'Penyesuaian manual oleh staff',
            );

            return back()->with('success', 'Poin pelanggan berhasil disesuaikan.');
        } catch (\RuntimeException $exception) {
            return back()->with('error', 'Poin gagal disesuaikan: '.$exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Poin gagal disesuaikan.');
        }
    }

    public function issueVoucher(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:customer_vouchers,code'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['fixed', 'percentage'])],
            'value' => ['required', 'numeric', 'min:0.01'],
            'min_spend' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        if ($validated['type'] === 'percentage' && $validated['value'] > 100) {
            return back()->withErrors(['value' => 'Diskon persentase voucher maksimal 100%.'])->withInput();
        }

        try {
            $this->loyalty->issueVoucher($customer, [
                'code' => $validated['code'],
                'name' => $validated['name'],
                'type' => $validated['type'],
                'value' => $validated['value'],
                'min_spend' => $validated['min_spend'] ?? 0,
                'expires_at' => $validated['expires_at'] ?? null,
            ]);

            return back()->with('success', 'Voucher pelanggan berhasil dibuat.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Voucher gagal dibuat.');
        }
    }

    public function crmOptOut(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $customer->update([
            'marketing_opt_in' => false,
            'crm_opt_out_at' => now(),
            'crm_opt_out_reason' => $validated['reason'] ?? 'Diminta pelanggan',
        ]);

        return back()->with('success', 'Pelanggan berhasil dikecualikan dari campaign CRM.');
    }

    public function crmOptIn(Customer $customer)
    {
        $customer->update([
            'marketing_opt_in' => true,
            'crm_opt_out_at' => null,
            'crm_opt_out_reason' => null,
        ]);

        return back()->with('success', 'Pelanggan kembali diizinkan menerima campaign CRM.');
    }

    /**
     * Get customer purchase history
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHistory(Customer $customer)
    {
        // Get transaction statistics
        $stats = Transaction::where('customer_id', $customer->id)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->selectRaw('
                COUNT(*) as total_transactions,
                SUM(COALESCE(taxable_amount, grand_total - COALESCE(tax_amount, 0))) as total_spent,
                MAX(created_at) as last_visit
            ')
            ->first();

        // Get recent transactions (last 5)
        $recentTransactions = Transaction::where('customer_id', $customer->id)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->select('id', 'invoice', 'grand_total', 'taxable_amount', 'tax_amount', 'payment_method', 'created_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'invoice' => $t->invoice,
                'total' => (int) ($t->taxable_amount ?? ($t->grand_total - (int) $t->tax_amount)),
                'payment_method' => $t->payment_method,
                'date' => \Carbon\Carbon::parse($t->created_at)->format('d M Y H:i'),
            ]);

        // Get frequently purchased products
        $frequentProducts = Transaction::where('customer_id', $customer->id)
            ->where('transactions.payment_status', 'paid')
            ->where('transactions.status', '!=', 'refunded')
            ->join('transaction_details', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'transaction_details.product_id', '=', 'products.id')
            ->selectRaw('products.id, products.title, SUM(transaction_details.qty) as total_qty')
            ->groupBy('products.id', 'products.title')
            ->orderByDesc('total_qty')
            ->limit(3)
            ->get();

        $loyaltySummary = $this->loyalty->customerSummary($customer);

        return response()->json([
            'success' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->no_telp,
                'loyalty_points' => (int) $customer->loyalty_points,
                'loyalty_tier' => $customer->tierLabel(),
                'next_tier' => $loyaltySummary['next_tier'],
                'loyalty_progress' => $loyaltySummary['progress'],
            ],
            'stats' => [
                'total_transactions' => (int) ($stats->total_transactions ?? 0),
                'total_spent' => (int) ($stats->total_spent ?? 0),
                'last_visit' => $stats->last_visit ? \Carbon\Carbon::parse($stats->last_visit)->format('d M Y') : null,
            ],
            'recent_transactions' => $recentTransactions,
            'frequent_products' => $frequentProducts,
        ]);
    }
}
