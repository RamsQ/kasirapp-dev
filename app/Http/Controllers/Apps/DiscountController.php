<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DiscountController extends Controller
{
    public function index(Request $request)
    {
        // Ambil diskon dengan relasi product utama dan bonusProduct
        $discounts = Discount::with(['product', 'bonusProduct']) 
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', '%' . $search . '%');
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $products = Product::select('id', 'title')->orderBy('title', 'asc')->get();

        return Inertia::render('Dashboard/Discounts/Index', [
            'discounts' => $discounts,
            'products'  => $products, 
            'filters'   => $request->only(['search'])
        ]);
    }

    public function create()
    {
        $products = Product::select('id', 'title')->orderBy('title', 'asc')->get();

        return Inertia::render('Dashboard/Discounts/Create', [
            'products' => $products
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'type'             => 'required|in:percentage,fixed,buy_get', // Tambahkan buy_get
            'value'            => 'required_if:type,percentage,fixed|nullable|numeric|min:0', // Opsional jika buy_get
            'min_transaction'  => 'required|numeric|min:0',
            'start_date'       => 'required|date',
            'end_date'         => 'required|date|after_or_equal:start_date',
            'product_id'       => 'nullable|exists:products,id',
            'bonus_product_id' => 'required_if:type,buy_get|nullable|exists:products,id', // Wajib jika tipe buy_get
        ]);

        Discount::create([
            'name'             => $request->name,
            'description'      => $request->description,
            'type'             => $request->type,
            'value'            => $request->type === 'buy_get' ? 0 : ($request->value ?? 0),
            'min_transaction'  => $request->min_transaction,
            'start_date'       => $request->start_date,
            'end_date'         => $request->end_date,
            'product_id'       => $request->product_id,
            'bonus_product_id' => $request->bonus_product_id, // Simpan ID produk bonus
            'is_active'        => true
        ]);

        return redirect()->route('discounts.index')->with('success', 'Promo Diskon berhasil dibuat!');
    }

    public function destroy($id)
    {
        $discount = Discount::findOrFail($id);
        $discount->delete();
        return back()->with('success', 'Promo Diskon dihapus.');
    }
}