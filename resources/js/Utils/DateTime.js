/**
 * Format tanggal dan jam dalam zona waktu yang sama dengan server.
 *
 * MASALAH ASAL
 *
 * Hampir seluruh tampilan waktu memakai bentuk ini:
 *
 *     new Date(value).toLocaleString("id-ID", { ... })
 *
 * Tanpa opsi `timeZone`, `toLocaleString` memakai **zona waktu browser**.
 * Padahal Laravel menyimpan dan mengirim waktu dalam zona waktu aplikasi,
 * yaitu `Asia/Jakarta` (lihat `config/app.php`).
 *
 * Hasilnya benar selama zona waktu browser juga WIB -- yaitu
 * selama setiap kasir memakai komputer yang pengaturannya/default-nya ke Asia/Jakarta.
 * Begitu ada satu perangkat yang berbeda -- ponsel di luar zona, komputer
 * yang pengaturan/default sistemnya keliru, atau emulator yang bawaan UTC --
 * jam di halaman langsung berbeda dari jam di struk dan di laporan. Kasir
 * melihat transaksi 14.30 sebagai 15.30 tanpa ada yang bisa dijelaskan.
 *
 * MENGGUNAKAN BERKAS INI
 *
 * Semua format waktu harus lewat sini. Fungsinya sengaja dibuat tipis:
 * satu tempat yang menentukan zona waktu, supaya tidak ada satu halaman
 * pun yang diam-diam memakai zona waktu browser.
 *
 * Format MONEY (`toLocaleString` pada angka) TIDAK boleh lewat sini --
 * yang diformat adalah angka, bukan waktu, dan zona waktu tidak berperan.
 */

/** Zona waktu aplikasi. Harus sama dengan `config/app.php`. */
export const APP_TIMEZONE = "Asia/Jakarta";

/**
 * Ubah nilai apa pun menjadi objek Date yang sah.
 *
 * Laravel mengirim `created_at` sebagai string ISO-8601 yang tidak selalu
 * memuat offset. `new Date("2026-09-30T14:30:00.000000Z")` dipahami
 * sebagai UTC, dan itulah yang dimaksud: jamnya sudah dikonversi ke UTC
 * oleh PHP sebelum dikirim.
 *
 * @param {string | number | Date | null | undefined} value
 * @returns {Date | null} null kalau nilai tidak bisa dibaca sebagai tanggal
 */
export function toDate(value) {
    if (value === null || value === undefined || value === "") return null;

    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * Tanggal dan jam lengkap: "30 Sep 2026, 14.30".
 *
 * @param {string | number | Date | null | undefined} value
 */
export function formatDateTime(value, options = {}) {
    const date = toDate(value);

    if (!date) return "-";

    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeStyle: "short",
        timeZone: APP_TIMEZONE,
        ...options,
    }).format(date);
}

/**
 * Jam saja: "14.30".
 *
 * @param {string | number | Date | null | undefined} value
 */
export function formatTime(value, options = {}) {
    const date = toDate(value);

    if (!date) return "-";

    return new Intl.DateTimeFormat("id-ID", {
        timeStyle: "short",
        timeZone: APP_TIMEZONE,
        ...options,
    }).format(date);
}

/**
 * Tanggal saja: "30 Sep 2026".
 *
 * @param {string | number | Date | null | undefined} value
 */
export function formatDate(value, options = {}) {
    const date = toDate(value);

    if (!date) return "-";

    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeZone: APP_TIMEZONE,
        ...options,
    }).format(date);
}

/**
 * Jam sekarang, zona waktu aplikasi.
 *
 * Dipakai untuk stempel waktu pada struk yang dicetak. `new Date()` tanpa
 * apa-apa akan menghasilkan jam lokal browser, sehingga struk yang dicetak
 * dari komputer dengan zona waktu keliru mencantumkan waktu yang salah --
 * dan struk adalah bukti resmi.
 */
export function nowStamp() {
    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeStyle: "short",
        timeZone: APP_TIMEZONE,
    }).format(new Date());
}