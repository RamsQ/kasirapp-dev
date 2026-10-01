<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Expense extends Model
{
    use HasFactory;

    /**
     * Sumber dana expense. Nilai ini menentukan account mana di neraca yang
     * bergerak, jadi TIDAK boleh bebas diisi: kalau ada typo, expense tetap
     * dipotong dari laba tapi tidak mengurangi kas atau hutang mana pun, dan
     * selisih neraca muncul tanpa ada yang menyadarinya.
     */
    public const SOURCE_CASH = 'Kas Laci';

    public const SOURCE_CAPITAL = 'Modal Luar';

    public const SOURCE_DEBT = 'Hutang Dagang';

    /** Diisi sistem oleh StockPurchaseRecorder, bukan oleh user. */
    public const SOURCE_STOCK_PURCHASE = 'Belanja Stok';

    /**
     * Uang dipindah dari laci ke rekening bank.
     *
     * Ini BUKAN pengeluaran: tidak ada yang berkurang total, uang cuma pindah
     * dari satu aset ke aset lain (laci -> bank). Tapi karena dipindah dari
     * laci, harus mengurangi Kas di Laci dan menambah Kas Bank, kalau tidak
     * laci akan terlihat menumpuk padahal uangnya sudah disetor.
     */
    public const SOURCE_BANK_DEPOSIT = 'Setor ke Bank';

    /** Pilihannya untuk form pengeluaran. */
    public const SOURCES = [
        self::SOURCE_CASH,
        self::SOURCE_BANK_DEPOSIT,
        self::SOURCE_CAPITAL,
        self::SOURCE_DEBT,
    ];

    /**
     * Sumber yang TIDAL boleh masuk hitungan "pengeluaran laci" di laporan.
     *
     * Daftar ini sengaja dibaca sebagai "pengecualian", bukan "daftar resmi".
     * Jadi kalau ada source lama atau nilai baru yang tidak dikenal, expense
     * itu tetap dianggap keluar dari kas. Itu pilihan yang aman: kalau ada
     * expense yang luput dicatat, lebih baik kasnya terlihat kurang daripada
     * neraca terlihat balance padahal uangnya hilang dari pembukuan.
     *
     * Perhatikan Modal Luar TIDAK ada di sini: uangnya memang keluar dari
     * laci, jadi harus mengurangi kas. Yang berkurang hanya sisi ekuitasnya.
     */
    public const NOT_A_DRAWER_OUTFLOW = [
        // Uang MASUK dari pinjaman: kas naik, hutang naik.
        self::SOURCE_DEBT,
        // Sudah ditangani lewat payment_method (tunai mengurangi kas, hutang
        // menambah hutang usaha), jadi jangan dihitung dua kali.
        self::SOURCE_STOCK_PURCHASE,
        // Sudah ditangani sendiri: mengurangi laci, menambah bank.
        self::SOURCE_BANK_DEPOSIT,
    ];

    /**
     * Sumber yang TIDAL boleh memotong laba.
     *
     * Bedanya dengan NOT_A_DRAWER_OUTFLOW: Modal Luar mengurangi kas (uangnya
     * benar-benar keluar), tapi TIDAK boleh memotong laba, karena itu penarikan
     * modal oleh pemilik, bukan biaya operasional.
     */
    public const NOT_AN_OPERATING_EXPENSE = [
        self::SOURCE_CAPITAL,
        self::SOURCE_BANK_DEPOSIT,
        ...self::NOT_A_DRAWER_OUTFLOW,
    ];

    /**
     * Properti fillable untuk mass assignment.
     * 'source' menentukan sumber dana (Kas Laci / Modal Luar / Hutang Dagang).
     * 'payment_method' membedakan belanja stok yang dibayar tunai atau hutang.
     */
    protected $fillable = [
        'user_id',
        'name',
        'category',
        'amount',
        'date',
        'image',
        'note',
        'source',
        'payment_method',
    ];

    protected $casts = [
        'amount' => 'integer',
        'date' => 'date:Y-m-d',
    ];

    /**
     * Menambahkan image_url ke dalam response JSON secara otomatis.
     */
    protected $appends = ['image_url'];

    /**
     * Expense yang benar-benar mengeluarkan uang dari kas.
     *
     * Memakai daftar pengecualian, bukan daftar putih, supaya source yang tidak
     * dikenal (typo, data lama, source baru) tetap terhitung sebagai outflow.
     * Kalau tidak begini, expense tersebut memotong laba tanpa mengurangi
     * account mana pun, dan selisih neraca muncul tanpa penjelasan.
     */
    public function scopeCashOutflow(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('source')
                ->orWhereNotIn('source', self::NOT_A_DRAWER_OUTFLOW);
        });
    }

    /**
     * Expense yang boleh memotong laba.
     *
     * Dipisah dari NOT_A_DRAWER_OUTFLOW karena dua hal berbeda: mengurangi kas
     * dan mengurangi laba tidak selalu sama. Modal Luar mengurangi kas tapi
     * tidak memotong laba.
     *
     * Scope ini dipakai FinancialReportService untuk KPI dan AnalyticsService
     * untuk tren per periode. Keduanya harus lewat sini. Kalau tiap laporan
     * menulis daftar sendiri, sooner or later satu laporan akan menghitung
     * beban operasional beda dari yang lain, dan selisihnya muncul sebagai
     * angka yang salah tanpa ada yang bisa mengaitkannya ke penyebabnya.
     */
    public function scopeOperatingExpense(Builder $query): Builder
    {
        return $query
            ->where('category', '!=', 'Pelunasan Hutang')
            ->where(function (Builder $query): void {
                $query->whereNull('source')
                    ->orWhereNotIn('source', self::NOT_AN_OPERATING_EXPENSE);
            });
    }

    /**
     * Relasi ke model User (Siapa petugas yang menginput).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Accessor untuk mendapatkan URL lengkap gambar nota.
     */
    public function getImageUrlAttribute()
    {
        if (! $this->image) {
            return null;
        }

        // Mengarahkan ke symlink storage (php artisan storage:link)
        return asset('storage/expenses/'.$this->image);
    }
}
