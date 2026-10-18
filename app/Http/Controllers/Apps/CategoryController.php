<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CategoryController extends Controller
{
    /**
     * Folder gambar kategori, relatif terhadap disk publik.
     *
     * Penting: path TIDAK boleh diawali `public/`.
     *
     * Disk `public` sudah berakar di `storage/app/public`, dan symlink
     * `public/storage` mengarah ke sana. Kalau path-nya ditulis
     * `public/category`, file tersimpan di
     * `storage/app/public/public/category` -- satu tingkat lebih dalam --
     * sedangkan frontend menampilkannya lewat `/storage/category/...`.
     * Hasilnya file benar-benar ada di server, tapi tidak pernah terlihat,
     * dan kasir mengira upload-nya gagal.
     *
     Hal ini juga jadi alasan disk-nya harus disebut eksplisit. Nilai
     * `FILESYSTEM_DISK` di `.env` bisa berubah antara lingkungan, dan
     * `storeAs()` tanpa argumen disk akan ikut berubah tempatnya tanpa
     * ada yang memperingatkan.
     */
    private const IMAGE_DIR = 'category';


    /**
     * Tampilkan daftar kategori.
     */
    public function index()
    {
        $categories = Category::when(request()->search, function ($query) {
            $query->where('name', 'like', '%'.request()->search.'%');
        })->latest()->paginate(10); // Mengubah paginate menjadi 10 agar lebih standar

        /*
         * `category.image` sudah berupa URL lengkap -- diubah oleh accessor
         * pada model Category, yang juga mengembalikan null untuk kategori
         * tanpa gambar. Jadi frontend cukup memakai `src={category.image}`
         * dan tidak perlu menyusun URL sendiri.
         */
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
            'image' => 'nullable|image|mimes:jpeg,jpg,png|max:2048',
            'name' => 'required|unique:categories,name',
            'description' => 'nullable',
        ]);

        $imageName = null;
        // Cek jika ada file gambar yang diunggah
        if ($request->hasFile('image')) {
            $imageName = $this->simpanGambar($request->file('image'));
        }

        // Create category
        Category::create([
            'image' => $imageName,
            'name' => $request->name,
            'description' => $request->description ?? '-', // Default strip jika kosong
        ]);

        return to_route('categories.index')->with('success', 'Kategori berhasil disimpan!');
    }

    /**
     * Simpan berkas gambar kategori dan kembalikan nama berkasnya.
     *
     * Disk dan folder selalu disebut eksplisit. Lihat catatan pada
     * `IMAGE_DIR` untuk alasan path tidak boleh diawali `public/`.
     */
    private function simpanGambar(\Illuminate\Http\UploadedFile $file): string
    {
        $nama = $file->hashName();

        // `storeAs()` milik UploadedFile tidak menerima argumen disk, jadi
        // disk harus disebutkan lewat facade Storage.
        Storage::disk('public')->putFileAs(self::IMAGE_DIR, $file, $nama);

        return $nama;
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
            'name' => 'required|unique:categories,name,'.$category->id,
            'image' => 'nullable|image|mimes:jpeg,jpg,png|max:2048',
            'description' => 'nullable',
        ]);

        $data = [
            'name' => $request->name,
            'description' => $request->description ?? '-',
        ];

        // Check jika ada gambar baru
        if ($request->hasFile('image')) {
            // Berkas lama dihapus setelah yang baru benar-benar tersimpan.
            //
            // Urutan ini penting: kalau dihapus lebih dulu dan penyimpanan
            // yang baru gagal, kategori kehilangan gambarnya tanpa ada
            // penggantinya. Kalau dihapus setelah, kondisi terburuknya hanya
            // ada berkas yatim yang tidak terpakai -- dan itu bisa dibersihkan.
            $lama = $category->imageFilename();
            $data['image'] = $this->simpanGambar($request->file('image'));

            $category->update($data);

            if ($lama && $lama !== $data['image']) {
                Storage::disk('public')->delete(self::IMAGE_DIR.'/'.$lama);
            }

            return to_route('categories.index')->with('success', 'Kategori berhasil diperbarui!');
        }

        $category->update($data);

        $category->update($data);

        return to_route('categories.index')->with('success', 'Kategori berhasil diperbarui!');
    }

    /**
     * Hapus kategori.
     *
     * Kategori yang masih dipakai produk tidak boleh dihapus.
     *
     * `products.category_id` punya batasan foreign key tanpa `onDelete`,
     * jadi menghapus kategori yang masih dipakai akan ditolak MySQL dan
     * kasir melihat pesan error teknis yang tidak menjelaskan apa pun --
     * biasanya sekadar "QueryException" atau nomor errno.
     *
     * Memeriksa jumlahnya lebih dulu memberi jawaban yang bisa
     * ditindaklanjuti: berapa produk yang menghalangi, dan apa yang harus
     * dilakukan. Menghapus kategori beserta produknya secara diam-diam adalah
     * pilihan yang lebih buruk -- produk hilang dari daftar dan struk, dan
     * tidak ada yang pernah ditanyakan.
     */
    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        $dipakai = Product::where('category_id', $category->id)->count();

        if ($dipakai > 0) {
            return to_route('categories.index')->with(
                'error',
                "Kategori \"{$category->name}\" masih dipakai oleh {$dipakai} produk. "
                .'Pindahkan produknya ke kategori lain dulu, baru hapus.'
            );
        }

        // `imageFilename()` mengambil nama berkas mentah. `image` sendiri
        // sudah berupa URL, jadi `basename()` di situ hanya kebetulan benar.
        $berkas = $category->imageFilename();

        if ($berkas) {
            Storage::disk('public')->delete(self::IMAGE_DIR.'/'.$berkas);
        }

        $category->delete();

        return to_route('categories.index')->with('success', 'Kategori berhasil dihapus!');
    }
}
