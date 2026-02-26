<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DiscountController extends Controller
{
    /**
     * Tampilan Daftar Promo
     */
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

    /**
     * Form Tambah Promo
     */
    public function create()
    {
        $products = Product::select('id', 'title')->orderBy('title', 'asc')->get();

        return Inertia::render('Dashboard/Discounts/Create', [
            'products' => $products
        ]);
    }

    /**
     * Simpan Promo Baru
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'type'             => 'required|in:percentage,fixed,buy_get',
            'value'            => 'required_if:type,percentage,fixed|nullable|numeric|min:0',
            
            // Syarat Minimal Belanja (Rp) wajib diisi HANYA untuk tipe 'fixed'
            'min_transaction'  => 'required_if:type,fixed|nullable|numeric|min:0',
            
            // Syarat Minimal Qty wajib diisi untuk tipe 'percentage' dan 'buy_get'
            'minimum_item'     => 'required_if:type,percentage,buy_get|nullable|numeric|min:1', 
            
            'start_date'       => 'required|date',
            'end_date'         => 'required|date|after_or_equal:start_date',
            'product_id'       => 'nullable|exists:products,id',
            'bonus_product_id' => 'required_if:type,buy_get|nullable|exists:products,id', 
        ]);

        Discount::create([
            'name'             => $request->name,
            'description'      => $request->description,
            'type'             => $request->type,
            'value'            => $request->type === 'buy_get' ? 0 : ($request->value ?? 0),
            
            // Logika Penyimpanan Syarat:
            // 1. Jika Fixed -> Simpan ke min_transaction (Rupiah)
            'min_transaction'  => $request->type === 'fixed' ? $request->min_transaction : 0,
            
            // 2. Jika Persentase atau Buy_Get -> Simpan ke minimum_item (Qty)
            'minimum_item'     => in_array($request->type, ['percentage', 'buy_get']) ? $request->minimum_item : 0,
            
            'start_date'       => $request->start_date,
            'end_date'         => $request->end_date,
            'product_id'       => $request->product_id,
            'bonus_product_id' => $request->bonus_product_id, 
            'is_active'        => true
        ]);

        return redirect()->route('discounts.index')->with('success', 'Promo Diskon berhasil dibuat!');
    }

    /**
     * Hapus Promo
     */
    public function destroy($id)
    {
        $discount = Discount::findOrFail($id);
        $discount->delete();
        return back()->with('success', 'Promo Diskon berhasil dihapus.');
    }
}