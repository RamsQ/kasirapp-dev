<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Imports\IngredientsImport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class IngredientController extends Controller
{
    /**
     * Menampilkan daftar bahan baku
     */
    public function index(Request $request)
    {
        $ingredients = Ingredient::when($request->search, function($query, $search) {
                $query->where('name', 'LIKE', '%' . $search . '%');
            })
            ->latest()
            ->get();
        
        return Inertia::render('Dashboard/Ingredients/Index', [
            'ingredients' => $ingredients,
            'filters'     => $request->only(['search']),
        ]);
    }

    /**
     * Menyimpan bahan baku baru (Tambah Manual)
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:255|unique:ingredients,name',
            'unit'      => 'required|string|max:50', 
            'buy_price' => 'required|numeric|min:0',
            'min_stock' => 'required|numeric|min:0',
            'stock'     => 'nullable|numeric|min:0',
        ]);

        // Gunakan Transaction untuk memastikan integritas data
        DB::transaction(function () use ($request) {
            Ingredient::create([
                'name'      => $request->name,
                'unit'      => $request->unit,
                'buy_price' => $request->buy_price,
                'min_stock' => $request->min_stock,
                'stock'     => $request->stock ?? 0,
            ]);
        });

        return redirect()->route('ingredients.index')->with('success', 'Bahan baku berhasil ditambahkan!');
    }

    /**
     * Update data bahan baku (Modal Edit & Inline Edit)
     * PERBAIKAN: Mencegah stok tertimpa menjadi 0 saat update field lain.
     */
    public function update(Request $request, Ingredient $ingredient)
    {
        $request->validate([
            'name'      => 'nullable|string|max:255|unique:ingredients,name,' . $ingredient->id,
            'unit'      => 'nullable|string|max:50',
            'min_stock' => 'nullable|numeric|min:0',
            'buy_price' => 'nullable|numeric|min:0',
            'stock'     => 'nullable|numeric|min:0',
        ]);

        // PROTEKSI STOK: Ambil data hanya yang memiliki nilai (bukan null atau empty string)
        $data = array_filter($request->only(['name', 'unit', 'min_stock', 'buy_price', 'stock']), function($value) {
            return $value !== null && $value !== '';
        });

        // Jika request berasal dari proses update detail (bukan stock in),
        // dan parameter stock tidak dikirim secara eksplisit oleh user, 
        // hapus dari array update agar tidak menimpa nilai database.
        if (!$request->has('stock')) {
            unset($data['stock']);
        }
        
        $ingredient->update($data);

        return redirect()->back()->with('success', 'Data diperbarui!');
    }

    /**
     * Menghapus data bahan baku
     */
    public function destroy(Ingredient $ingredient)
    {
        $ingredient->delete();
        return redirect()->back()->with('success', 'Bahan baku berhasil dihapus!');
    }

    /**
     * Proses import data dari Excel
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls'
        ]);

        try {
            Excel::import(new IngredientsImport, $request->file('file'));
            return redirect()->route('ingredients.index')->with('success', 'Bahan baku berhasil diimpor!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Gagal mengimpor data: ' . $e->getMessage());
        }
    }

    /**
     * Mengunduh Template Excel untuk Bahan Baku
     */
    public function template()
    {
        $header = [
            ['nama_bahan', 'satuan', 'harga_modal', 'stok_saat_ini', 'batas_minimum']
        ];

        $ingredients = Ingredient::latest()->get()->map(function($item) {
            return [
                $item->name,
                $item->unit ?? 'pcs',
                $item->buy_price,
                $item->stock,
                $item->min_stock
            ];
        })->toArray();

        $exportData = array_merge($header, $ingredients);

        return Excel::download(new class($exportData) implements FromArray {
            protected $data;
            public function __construct(array $data) { $this->data = $data; }
            public function array(): array { return $this->data; }
        }, 'template_bahan_baku_' . date('Ymd_His') . '.xlsx');
    }
}