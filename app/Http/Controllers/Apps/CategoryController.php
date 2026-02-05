<?php

namespace App\Http\Controllers\Apps;

use Inertia\Inertia;
use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    /**
     * Tampilkan daftar kategori.
     */
    public function index()
    {
        $categories = Category::when(request()->search, function ($query) {
            $query->where('name', 'like', '%' . request()->search . '%');
        })->latest()->paginate(10); // Mengubah paginate menjadi 10 agar lebih standar

        return Inertia::render('Dashboard/Categories/Index', [
            'categories' => $categories,
        ]);
    }

    /**
     * Form tambah kategori.
     */
    public function create()
    {
        return Inertia::render('Dashboard/Categories/Create');
    }

    /**
     * Simpan kategori baru.
     */
    public function store(Request $request)
    {
        /**
         * Validasi: Gambar dan Deskripsi sekarang bersifat opsional (nullable)
         */
        $request->validate([
            'image'       => 'nullable|image|mimes:jpeg,jpg,png|max:2048',
            'name'        => 'required|unique:categories,name',
            'description' => 'nullable'
        ]);

        $imageName = null;
        // Cek jika ada file gambar yang diunggah
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $image->storeAs('public/category', $image->hashName());
            $imageName = $image->hashName();
        }

        // Create category
        Category::create([
            'image'       => $imageName,
            'name'        => $request->name,
            'description' => $request->description ?? '-' // Default strip jika kosong
        ]);

        return to_route('categories.index')->with('success', 'Kategori berhasil disimpan!');
    }

    /**
     * Form edit kategori.
     */
    public function edit(Category $category)
    {
        return Inertia::render('Dashboard/Categories/Edit', [
            'category' => $category,
        ]);
    }

    /**
     * Update kategori.
     */
    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name'        => 'required|unique:categories,name,' . $category->id,
            'image'       => 'nullable|image|mimes:jpeg,jpg,png|max:2048',
            'description' => 'nullable'
        ]);

        $data = [
            'name'        => $request->name,
            'description' => $request->description ?? '-',
        ];

        // Check jika ada gambar baru
        if ($request->hasFile('image')) {
            // Hapus gambar lama jika ada
            if ($category->image) {
                Storage::disk('local')->delete('public/category/' . basename($category->image));
            }

            // Upload gambar baru
            $image = $request->file('image');
            $image->storeAs('public/category', $image->hashName());
            $data['image'] = $image->hashName();
        }

        $category->update($data);

        return to_route('categories.index')->with('success', 'Kategori berhasil diperbarui!');
    }

    /**
     * Hapus kategori.
     */
    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        // Hapus file gambar dari storage jika ada
        if ($category->image) {
            Storage::disk('local')->delete('public/category/' . basename($category->image));
        }

        $category->delete();

        return to_route('categories.index')->with('success', 'Kategori berhasil dihapus!');
    }
}