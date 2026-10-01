/**
 * Label dan status pembayaran.
 *
 * Kenapa file ini ada: label metode pembayaran sebelumnya ditulis ulang di
 * empat tempat terpisah (struk thermal, struk A4, draf bill, dan riwayat),
 * dan tiap versi punya daftar berbeda. Akibatnya satu receipt bisa menulis
 * "QRIS STATIS" sementara yang lain menulis "QRIS MANUAL" untuk pembayaran
 * yang sama. Di sini jadi satu sumber kebenaran.
 */

/**
 * Label yang enak dibaca manusia.
 *
 * `qris` sengaja tidak dipetakan: nilai itu tidak pernah dipakai di
 * `transactions.payment_method`. Nilainya `qris_manual`. Sebelumnya ada
 * pemeriksaan `=== 'qris'` di struk thermal yang tidak akan pernah benar,
 * jadi bagian "Bukti Bayar QRIS" di struk tidak pernah tampil sama sekali.
 */
export function paymentLabel(method) {
    const m = (method || '').toLowerCase();

    switch (m) {
        case 'cash':
            return 'TUNAI';
        case 'qris_manual':
            return 'QRIS STATIS';
        case 'midtrans':
            return 'QRIS (MIDTRANS)';
        case 'xendit':
            return 'QRIS (XENDIT)';
        case 'transfer':
            return 'TRANSFER';
        default:
            return (method || 'TUNAI').toUpperCase();
    }
}

/**
 * Label singkat untuk badge di layar kasir.
 */
export function paymentBadgeLabel(method) {
    const m = (method || '').toLowerCase();

    switch (m) {
        case 'cash':
            return 'TUNAI';
        case 'qris_manual':
            return 'QRIS STATIS';
        case 'midtrans':
            return 'QRIS MIDTRANS';
        case 'xendit':
            return 'QRIS XENDIT';
        case 'transfer':
            return 'TRANSFER';
        default:
            return (method || 'TUNAI').toUpperCase();
    }
}

/**
 * Apakah transaksi sudah lunas?
 *
 * Penting: JANGAN pakai `cash > 0` sebagai penanda. Untuk pembayaran non-tunai
 * field `cash` memang 0 karena tidak ada uang tunai yang diterima, sehingga
 * struk akan mencetak "BELUM LUNAS" untuk order yang sudah dibayar QRIS.
 * Yang benar adalah `payment_status`.
 *
 * Data lama mungkin tidak punya `payment_status`, jadi `cash` dipakai sebagai
 * cadangan hanya kalau statusnya memang tidak ada.
 */
export function isPaid(transaction) {
    if (!transaction) return false;

    const status = (transaction.payment_status || '').toLowerCase();
    if (status) return status === 'paid';

    return Number(transaction.cash || 0) > 0;
}

/**
 * Transaksi yang dibayar pelanggan sendiri lewat QRIS dinamis sebelum datang
 * ke kasir. Struk untuk ini perlu berbeda: tidak ada kembalian, dannominalnya
 * sudah masuk sebelum pesanan diserahkan.
 */
export function isSelfPaidGateway(transaction) {
    if (!transaction) return false;

    const m = (transaction.payment_method || '').toLowerCase();

    return (m === 'midtrans' || m === 'xendit') && isPaid(transaction);
}
