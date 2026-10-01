<?php

namespace App\Support;

/**
 * Menyeragamkan nomor telepon ke format internasional yang diminta Fonnte.
 *
 * MASALAH ASAL
 *
 * Nomor pelanggan disimpan apa adanya dari form. Kasir mengetik seperti yang
 * biasa diketik manusia: `0812-3456-7890`, `+62 812 3456 7890`, atau
 * `81234567890`. `CrmMessageSender` mengirimkannya apa adanya, dan Fonnte
 * hanya menerima format internasional tanpa tanda plus dan tanpa nol di
 * depan: `6281234567890`.
 *
 * Akibatnya nomor yang sebenarnya valid tetap ditolak provider. Kegagalannya
 * tidak muncul sebagai satu error yang jelas, tapi sebagai `failed` di baris
 * delivery untuk setiap pelanggan — jadi campaign besar bisa setengah gagal
 * dan pemicunya tidak kelihatan.
 *
 * KENAPA TIDAK SEKADAR MENGIRIMKAN APA ADANYA
 *
 * Karena tidak ada normalisasi di mana pun di rantai ini, satu pelanggan
 * bisa punya empat format berbeda dalam empat baris berbeda. Makin banyak
 * pelanggan, makin besar peluang sebagian campaign gagal.
 *
 * ATURAN
 *
 * Semua non-digit dibuang (spasi, tanda hubung, kurung, titik). Lalu:
 *  - sudah `62`  -> dipakai apa adanya
 *  - mulai `0`   -> nol depan diganti `62`
 *  - mulai `8`   -> ditambahkan `62`
 *  - selain itu  -> dianggap sudah kode negara tanpa nol, ditambahkan `62`
 *
 * Nomor telepon yang sudah benar seperti seperti `62812...` tidak
 * diubah sama sekali.
 */
final class IndonesianPhoneNumber
{
    /**
     * Panjang digit AFTER kode negara `62`. Nomor seluler Indonesia umumnya
     * 9-11 digit; angka tetap (mis. `021`) dihitung dengan panjang yang
     * lebih pendek, jadi batas bawah longgar.
     */
    private const MIN_NATIONAL_LENGTH = 8;

    private const MAX_NATIONAL_LENGTH = 13;

    /**
     * Ubah input bebas menjadi `62XXXXXXXXXX`, atau null kalau tidak
     * mungkin dibaca sebagai nomor Indonesia.
     */
    public static function toInternational(?string $raw): ?string
    {
        $national = self::nationalDigits($raw);

        if ($national === null) {
            return null;
        }

        return '62'.$national;
    }

    /**
     * Apakah nomor ini layak dikirim ke Fonnte.
     */
    public static function isDeliverable(?string $raw): bool
    {
        return self::nationalDigits($raw) !== null;
    }

    /**
     * Alasan kalau nomor TIDAK bisa dikirim, dalam bahasa kasir.
     *
     * Nilai yang diketik selalu ikut disebut, termasuk kalau sama sekali
     * tidak mengandung angka. Kasir perlu melihat persis nilai mana yang
     * ditolak. Kalau hanya "Panjang tidak sesuai" tanpa menyebut isinya,
     * dia akan memeriksa kolom yang salah.
     */
    public static function rejectionReason(?string $raw): ?string
    {
        $trimmed = trim((string) $raw);

        if ($trimmed === '') {
            return 'Nomor WhatsApp belum diisi.';
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return 'Nomor "'.$trimmed.'" tidak mengandung angka. '
                .'Contoh yang benar: 081234567890 atau 6281234567890.';
        }

        if (self::nationalDigits($raw) === null) {
            return 'Nomor "'.$trimmed.'" bukan nomor Indonesia yang valid. '
                .'Contoh yang benar: 081234567890 atau 6281234567890.';
        }

        return null;
    }

    /**
     * Digit nasional TANPA kode negara dan TANPA nol depan.
     *
     * @return string|null null kalau tidak bisa dibaca sebagai nomor Indonesia
     */
    private static function nationalDigits(?string $raw): ?string
    {
        $trimmed = trim((string) $raw);

        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return null;
        }

        // Buang satu `+` kalau ada, tapi `+` sudah ikut hilang di atas.
        // Yang tersisa hanya digit, jadi cukup dilihat dari depan.
        if (str_starts_with($digits, '62')) {
            $national = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $national = $digits;
        } else {
            // Tanpa awalan 0/8/62: dianggap kode negara lain atau nomor
            // internasional, tapi tetep dipaksa ke `62` karena aplikasi ini
            // hanya melayani nomor Indonesia.
            $national = $digits;
        }

        $length = strlen($national);

        if ($length < self::MIN_NATIONAL_LENGTH || $length > self::MAX_NATIONAL_LENGTH) {
            return null;
        }

        return $national;
    }
}
