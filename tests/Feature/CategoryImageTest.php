<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT: Modul Kategori -- penghapusan dan gambar.
 *
 * Dua cacat yang dilaporkan terpisah, tapi akar yang sebenarnya lebih dalam
 * dari yang terlihat.
 *
 * 1. Hapus kategori memunculkan pesan error.
 *
 *    `products.category_id` punya foreign key tanpa `onDelete`, jadi
 *    menghapus kategori yang masih dipakai ditolak MySQL. Kasir hanya
 *    melihat "QueryException" atau nomor errno -- tidak ada yang tahu
 *    harus apa.
 *
 * 2. Gambar "tidak tersimpan".
 *
 *    Berkasnya sebenarnya tersimpan dengan benar. Yang rusak ada di dua
 *    tempat yang terpisah:
 *
 *    a. `storeAs('public/category', ...)` memakai disk default. Disk
 *       `public` berakar di `storage/app/public`, sehingga path-nya
 *       menjadi `storage/app/public/public/category/...` satu tingkat
 *       terlalu dalam, sementara frontend menampilkannya lewat
 *       `/storage/category/...`.
 *
 *    b. Accessor `image()` pada model Category SELALU mengembalikan
 *       `asset('/storage/category/'.$value)`, termasuk ketika kolomnya
 *       kosong. Untuk kategori tanpa gambar hasilnya
 *       `http://host/storage/category/` -- URL yang menunjuk ke FOLDER.
 *       Frontend memeriksa `category.image ? ... : ...`, melihat string
 *       URL sebagai nilai yang benar, lalu menampilkan gambar rusak.
 *       Kasir menyimpulkan upload-nya gagal.
 *
 *       Aksesor juga memakai `asset()`, yang ikut `APP_URL`. Sebelum
 *       `APP_URL` diperbaiki, gambar di server produksi akan menunjuk ke
 *       `127.0.0.1`.
 */
class CategoryImageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['products.index', 'categories.index'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $this->admin->assignRole($role);
        $this->admin->givePermissionTo(['products.index', 'categories.index']);
    }

    protected function kategori(string $nama = 'Makanan'): Category
    {
        return Category::create([
            'name' => $nama,
            'description' => '-',
        ]);
    }

    protected function produk(Category $kategori, string $nama = 'Nasi Goreng'): Product
    {
        return Product::create([
            'category_id' => $kategori->id,
            'title' => $nama,
            'buy_price' => 12000,
            'sell_price' => 25000,
            'stock' => 100,
        ]);
    }

    // ---------------------------------------------------------------------
    // BUG 1: Hapus kategori
    // ---------------------------------------------------------------------

    public function test_kategori_yang_masih_dipakai_produk_tidak_bisa_dihapus(): void
    {
        $kategori = $this->kategori('Makanan');
        $this->produk($kategori, 'Nasi Goreng');
        $this->produk($kategori, 'Mie Goreng');

        $response = $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $kategori->id));

        $response->assertRedirect(route('categories.index'));
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $kategori->id]);
        $this->assertDatabaseCount('products', 2);

    }

    public function test_pesan_menyebut_berapa_produk_dan_nama_kategorinya(): void
    {
        $kategori = $this->kategori('Makanan');
        $this->produk($kategori, 'Nasi Goreng');
        $this->produk($kategori, 'Mie Goreng');

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $kategori->id))
            ->assertSessionHas('error');

        $pesan = (string) session('error');

        $this->assertStringContainsString('2 produk', $pesan,
            'Pesan harus menyebut berapa produk yang menghalangi, supaya kasir '
            .'tahu apa yang harus dilakukan.');
        $this->assertStringContainsString('Makanan', $pesan,
            'Pesan harus menyebut nama kategorinya.');
    }

    public function test_pesan_tidak_menampilkan_detail_teknis(): void
    {
        $kategori = $this->kategori();
        $this->produk($kategori);

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $kategori->id))
            ->assertSessionHas('error');

        $pesan = (string) session('error');

        foreach (['QueryException', 'errno', 'SQLSTATE', 'Integrity constraint'] as $kata) {
            $this->assertStringNotContainsString($kata, $pesan,
                "Pesan error tidak boleh menampilkan detail teknis: {$kata}");
        }
    }

    public function test_kategori_tanpa_produk_tetap_bisa_dihapus(): void
    {
        $kategori = $this->kategori('Kosong');

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $kategori->id))
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $this->assertDatabaseMissing('categories', ['id' => $kategori->id]);
    }

    // ---------------------------------------------------------------------
    // BUG 2: Gambar
    // ---------------------------------------------------------------------

    public function test_kategori_tanpa_gambar_tidak_menghasilkan_url(): void
    {
        $kategori = $this->kategori('Tanpa Gambar');

        $this->assertNull($kategori->image,
            'Accessor image() harus mengembalikan null untuk kolom kosong. '
            .'Kalau tidak, frontend mengira kategori ini punya gambar dan '
            .'menampilkan URL yang menunjuk ke folder.');
    }

    public function test_gambar_dibaca_sebagai_url_lengkap(): void
    {
        $kategori = $this->kategori('Ada Gambar');
        $kategori->update(['image' => 'abc123.jpg']);

        $this->assertStringEndsWith('/storage/category/abc123.jpg', (string) $kategori->image,
            'Nilai yang dibaca frontend harus sudah berupa URL, bukan nama berkas.');
    }

    public function test_nama_berkas_mentah_tersedia_untuk_sistem_berkas(): void
    {
        $kategori = $this->kategori('Nama Berkas');
        $kategori->update(['image' => 'abc123.jpg']);

        $this->assertSame('abc123.jpg', $kategori->imageFilename());
        $this->assertNull($this->kategori('Kosong')->imageFilename());
    }

    public function test_gambar_disimpan_di_lokasi_yang_bisa_diakses_publik(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('categories.store'), [
                'name' => 'Minuman',
                'description' => '-',
                'image' => UploadedFile::fake()->image('minuman.jpg'),
            ])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHasNoErrors();

        $kategori = Category::where('name', 'Minuman')->firstOrFail();

        $this->assertNotNull($kategori->getRawOriginal('image'),
            'Nama berkas harus tersimpan di database.');

        // Kunciannya: path tanpa awalan `public/`.
        Storage::disk('public')->assertExists('category/'.$kategori->getRawOriginal('image'));

        Storage::disk('public')->assertMissing('public/category/'.$kategori->getRawOriginal('image'));
    }

    public function test_daftar_kategori_mengirim_gambar_yang_bisa_dirender(): void
    {
        $kategori = $this->kategori('Dessert');
        $kategori->update(['image' => 'abc123.jpg']);

        $response = $this->actingAs($this->admin)->get(route('categories.index'));
        $response->assertOk();

        $baris = $response->viewData('page')['props']['categories']['data'][0];

        $this->assertStringEndsWith('/storage/category/abc123.jpg', (string) $baris['image']);
    }

    public function test_halaman_edit_juga_mengirim_gambar_yang_bisa_dirender(): void
    {
        $kategori = $this->kategori('Edit');
        $kategori->update(['image' => 'def456.jpg']);

        $response = $this->actingAs($this->admin)
            ->get(route('categories.edit', $kategori->id));

        $response->assertOk();
        $this->assertStringEndsWith(
            '/storage/category/def456.jpg',
            (string) $response->viewData('page')['props']['category']['image']
        );
    }

    public function test_mengganti_gambar_menghapus_berkas_lama(): void
    {
        Storage::fake('public');

        $lama = UploadedFile::fake()->image('lama.jpg');
        $kategori = $this->kategori('Berubah');
        $kategori->update(['image' => $lama->hashName()]);
        Storage::disk('public')->put('category/'.$lama->hashName(), 'isi');

        $this->actingAs($this->admin)
            ->put(route('categories.update', $kategori->id), [
                'name' => 'Berubah',
                'description' => '-',
                'image' => UploadedFile::fake()->image('baru.jpg'),
            ])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHasNoErrors();

        $kategori->refresh();
        $baru = $kategori->getRawOriginal('image');

        $this->assertNotSame($lama->hashName(), $baru);
        Storage::disk('public')->assertExists('category/'.$baru);
        Storage::disk('public')->assertMissing('category/'.$lama->hashName(),
            'Gambar lama harus dihapus setelah yang baru tersimpan. Kalau dihapus '
            .'lebih dulu dan penyimpanan baru gagal, kategorinya kehilangan '
            .'gambar tanpa penggantinya.');
    }

    public function test_mengganti_gambar_tanpa_gambar_baru_tidak_menghapus_yang_lama(): void
    {
        Storage::fake('public');

        $kategori = $this->kategori('Tanpa Ganti');
        $kategori->update(['image' => 'tetap.jpg']);
        Storage::disk('public')->put('category/tetap.jpg', 'isi');

        $this->actingAs($this->admin)
            ->put(route('categories.update', $kategori->id), [
                'name' => 'Tanpa Ganti Baru',
                'description' => '-',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('tetap.jpg', $kategori->fresh()->getRawOriginal('image'));
        Storage::disk('public')->assertExists('category/tetap.jpg');
    }

    public function test_menghapus_kategori_menghapus_berkas_gambarnya(): void
    {
        Storage::fake('public');

        $kategori = $this->kategori('Berhapus');
        $kategori->update(['image' => 'hapus.jpg']);
        Storage::disk('public')->put('category/hapus.jpg', 'isi');

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $kategori->id))
            ->assertSessionHas('success');

        Storage::disk('public')->assertMissing('category/hapus.jpg');
    }

    public function test_berkas_yatim_tidak_bikin_error_saat_menghapus(): void
    {
        Storage::fake('public');

        // Kategori menunjuk berkas yang sudah tidak ada -- misalnya hasil
        // pembersihan manual folder.
        $kategori = $this->kategori('Berkas Hilang');
        $kategori->update(['image' => 'hilang.jpg']);

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $kategori->id))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $kategori->id]);
    }

    public function test_nama_berkas_dilindungi_dari_path_traversal(): void
    {
        $kategori = $this->kategori('Traversal');
        $kategori->update(['image' => '../../../../etc/passwd']);

        $this->assertSame('passwd', $kategori->imageFilename(),
            'imageFilename() harus mengambil basename supaya path di luar folder '
            .'gambar tidak bisa dihapus.');
    }
}
