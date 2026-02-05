<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Exports\RecipeTemplateExport; 
use App\Imports\RecipeImport;         
use Maatwebsite\Excel\Facades\Excel;

class RecipeController extends Controller
{
    /**
     * Menampilkan halaman manajemen resep dengan pencarian dan analisis margin
     */
    public function index(Request $request)
    {
        $products = Product::query()
            ->has('recipes')
            ->with(['recipes.ingredient'])
            ->when($request->search, function ($query, $search) {
                $query->where('title', 'like', '%' . $search . '%');
            })
            // Pastikan sell_price diambil untuk perhitungan di Frontend
            ->select('id', 'title', 'type', 'sell_price', 'cost_price')
            ->orderBy('title', 'asc')
            ->paginate(10)
            ->withQueryString();

        $allProducts = Product::select('id', 'title', 'type', 'sell_price')
            ->orderBy('title', 'asc')
            ->get();

        $ingredients = Ingredient::orderBy('name', 'asc')->get();

        // Statistik Ringkasan untuk Dashboard Top Cards
        $stats = [
            'total_menu' => Product::has('recipes')->count(),
            'avg_hpp' => Product::has('recipes')->avg('cost_price') ?? 0,
            'total_ingredients' => $ingredients->count()
        ];
        
        return Inertia::render('Dashboard/Recipes/Index', [
            'products'    => $products,    
            'allProducts' => $allProducts, 
            'ingredients' => $ingredients,
            'stats'       => $stats,
            'filters'     => $request->all(['search'])
        ]);
    }

    /**
     * Simpan/Update Resep
     */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'ingredients' => 'required|array|min:1',
            'ingredients.*.ingredient_id' => 'required|exists:ingredients,id',
            'ingredients.*.qty_needed' => 'required|numeric|min:0.00001',
        ]);

        try {
            DB::transaction(function () use ($request) {
                $product = Product::findOrFail($request->product_id);
                Recipe::where('product_id', $product->id)->delete();

                foreach ($request->ingredients as $item) {
                    Recipe::create([
                        'product_id'    => $product->id,
                        'ingredient_id' => $item['ingredient_id'],
                        'qty_needed'    => $item['qty_needed'],
                    ]);
                }

                $this->calculateProductHpp($product->id);
            });

            return back()->with('success', 'Struktur resep berhasil diperbarui!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            DB::transaction(function () use ($request, $id) {
                if ($request->type == 'massal') {
                    Recipe::where('product_id', $id)->delete();
                    Product::where('id', $id)->update(['cost_price' => 0]);
                    $this->msg = "Daftar resep berhasil dikosongkan.";
                } else {
                    $recipe = Recipe::findOrFail($id);
                    $productId = $recipe->product_id;
                    $recipe->delete();
                    $this->calculateProductHpp($productId);
                    $this->msg = "Bahan baku berhasil dihapus.";
                }
            });
            return back()->with('success', $this->msg);
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    public function syncAll(Request $request)
    {
        try {
            $this->syncAllHpp();
            return back()->with('success', 'Sinkronisasi modal selesai!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    public function template()
    {
        return Excel::download(new RecipeTemplateExport, 'template_resep.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|mimes:xlsx,xls']);
        try {
            Excel::import(new RecipeImport, $request->file('file'));
            $this->syncAllHpp();
            return back()->with('success', 'Import resep selesai!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    private function calculateProductHpp($productId)
    {
        $product = Product::with('recipes.ingredient')->find($productId);
        if ($product) {
            $totalHpp = $product->recipes->sum(function ($recipe) {
                return (float)$recipe->qty_needed * (float)($recipe->ingredient->buy_price ?? 0);
            });
            $product->update(['cost_price' => $totalHpp]);
        }
    }

    public function syncAllHpp()
    {
        $products = Product::has('recipes')->with('recipes.ingredient')->get();
        foreach ($products as $product) {
            $totalHpp = $product->recipes->sum(function ($recipe) {
                return (float)$recipe->qty_needed * (float)($recipe->ingredient->buy_price ?? 0);
            });
            $product->update(['cost_price' => $totalHpp]);
        }
        Product::doesntHave('recipes')->update(['cost_price' => 0]);
    }
}