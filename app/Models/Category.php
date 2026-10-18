<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'image', 'name', 'description',
    ];

    /**
     * products
     *
     * @return void
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * image
     *
     * Mengubah nama berkas di database menjadi URL yang bisa dipakai langsung
     * oleh atribut `src`.
     *
     * PENTING: harus mengembalikan `null` kalau kolomnya kosong.
     *
     * Sebelumnya accessor ini selalu mengembalikan
     * `asset('/storage/category/'.$value)`. Untuk kategori tanpa gambar,
     * `$value` adalah null, jadi hasilnya `http://host/storage/category/`
     * -- URL yang menunjuk ke FOLDER, bukan berkas gambar. Frontend yang
     * memeriksa `category.image ? ... : ...` akan melihat string URL sebagai
     * nilai yang benar, lalu menampilkan gambar rusak untuk kategori yang
     * memang tidak punya gambar. Kasir menyimpulkan upload-nya gagal,
     * padahal tidak ada yang gagal.
     */
    protected function image(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => blank($value)
                ? null
                : rtrim(asset('storage/category/'.rawurlencode((string) $value)), '/'),
            // Penulisan tetap menyimpan nama berkas mentah. Tanpa `set`
            // eksplisit, nilai yang baru ditulis bisa ikut ter-transformasi
            // saat dibaca kembali oleh kode yang sebenarnya butuh nama
            // berkas.
            set: fn ($value) => $value,
        );
    }

    /**
     * Nama berkas gambar mentah, untuk keperluan sistem berkas.
     *
     * `image` sudah berupa URL, jadi `basename($category->image)` hanya
     * kebetulan menghasilkan nama yang benar. Untuk menghapus berkas,
     * nama yang benar diambil dari sini.
     */
    public function imageFilename(): ?string
    {
        $nama = $this->getRawOriginal('image');

        return blank($nama) ? null : basename((string) $nama);
    }
}
