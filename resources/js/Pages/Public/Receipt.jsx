import React from "react";
import { Head, Link } from "@inertiajs/react";
import { IconCheck, IconClock, IconX, IconPrinter, IconChevronLeft } from "@tabler/icons-react";
import { paymentLabel } from "@/Utils/PaymentLabels";

const rp = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(Number(value || 0));

const waktu = (iso) => {
    if (!iso) return "-";
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return "-";
    return d.toLocaleString("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
};

/**
 * Struk digital untuk pelanggan pesanan menu QR.
 *
 * BUKAN struk resmi. Struk resmi tetap dicetak kasir. Halaman ini supaya
 * pelanggan punya bukti di HP-nya sendiri tanpa harus minta struk kertas.
 *
 * Status di sini berasal dari server. Kalau pesanan masih menunggu
 * pembayaran, halaman menampilkan "MENUNGGU PEMBAYARAN" dan pelanggan bisa
 * menyelesaikan pembayaran tanpa balik ke daftar menu.
 */
export default function PublicReceipt({ receipt, store }) {
    const { items, totals, payment } = receipt;

    const pending = payment.status === "pending";
    const failed = payment.status === "failed" || payment.status === "expired";
    const paid = totals.paid;

    const tone = paid
        ? { text: "text-emerald-600", bg: "bg-emerald-50", border: "border-emerald-200", darkBg: "dark:bg-emerald-950/30", darkBorder: "dark:border-emerald-800" }
        : pending
            ? { text: "text-amber-600", bg: "bg-amber-50", border: "border-amber-200", darkBg: "dark:bg-amber-950/30", darkBorder: "dark:border-amber-800" }
            : failed
                ? { text: "text-rose-600", bg: "bg-rose-50", border: "border-rose-200", darkBg: "dark:bg-rose-950/30", darkBorder: "dark:border-rose-800" }
                : { text: "text-slate-600", bg: "bg-slate-50", border: "border-slate-200", darkBg: "dark:bg-slate-800", darkBorder: "dark:border-slate-700" };

    const Headline = paid ? "LUNAS" : pending ? "MENUNGGU PEMBAYARAN" : failed ? "PEMBAYARAN GAGAL" : "BELUM BAYAR";

    return (
        <div className="min-h-screen bg-slate-100 dark:bg-slate-950 font-sans text-slate-900 dark:text-slate-100">
            <Head title={`Struk ${receipt.ref_number} | ${store?.name || "LakuPOS"}`} />

            <div className="max-w-md mx-auto px-4 py-6 pb-28">
                <Link
                    href={route("public.menu", receipt.order_type === "dine_in" ? { type: "table" } : { type: "takeaway" })}
                    className="inline-flex items-center gap-1.5 text-xs font-black uppercase tracking-widest text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 mb-4"
                >
                    <IconChevronLeft size={16} /> Kembali ke menu
                </Link>

                <div className="bg-white dark:bg-slate-900 rounded-[2rem] shadow-xl overflow-hidden border border-slate-200 dark:border-slate-800">
                    {/* KEPALA TOKO */}
                    <div className="bg-slate-900 text-white px-6 py-6 text-center">
                        {store?.logo && (
                            <img src={store.logo} alt="" className="w-14 h-14 mx-auto mb-3 object-contain" />
                        )}
                        <h1 className="text-lg font-black uppercase italic tracking-tighter leading-none">
                            {store?.name || "LakuPOS"}
                        </h1>
                        {store?.address && <p className="text-[10px] opacity-70 mt-1.5 leading-relaxed">{store.address}</p>}
                        {store?.phone && <p className="text-[10px] opacity-70 mt-0.5">{store.phone}</p>}
                    </div>

                    {/* STATUS PEMBAYARAN */}
                    <div className={`px-6 py-5 text-center border-b ${tone.bg} ${tone.border} ${tone.darkBg} ${tone.darkBorder}`}>
                        <div className={`inline-flex items-center gap-2 text-2xl font-black uppercase italic tracking-tighter ${tone.text}`}>
                            {paid ? (
                                <IconCheck size={26} stroke={3} />
                            ) : pending ? (
                                <IconClock size={26} className="animate-pulse" />
                            ) : (
                                <IconX size={26} stroke={3} />
                            )}
                            {Headline}
                        </div>
                        <p className="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-1.5">
                            {payment.method ? `Metode: ${paymentLabel(payment.method)}` : "Belum ada pembayaran"}
                        </p>
                        {paid && payment.paid_at && (
                            <p className="text-[10px] text-slate-400 mt-0.5">Dibayar {waktu(payment.paid_at)}</p>
                        )}
                    </div>

                    {/* IDENTITAS PESANAN */}
                    <div className="px-6 py-4 border-b border-dashed border-slate-200 dark:border-slate-700 text-[11px] space-y-1.5">
                        <div className="flex justify-between">
                            <span className="text-slate-400 font-bold uppercase">Kode</span>
                            <span className="font-black">#{String(receipt.ref_number || "").replace(/\D/g, "") || "----"}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-slate-400 font-bold uppercase">No. Antrian</span>
                            <span className="font-black">{receipt.queue_number || "-"}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-slate-400 font-bold uppercase">Nama</span>
                            <span className="font-black text-right">{receipt.customer_name}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-slate-400 font-bold uppercase">Waktu</span>
                            <span className="font-black">{waktu(receipt.created_at)}</span>
                        </div>
                    </div>

                    {/* RINCIAN ITEM */}
                    <div className="px-6 py-4 border-b border-dashed border-slate-200 dark:border-slate-700">
                        <p className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-3">Pesanan</p>
                        <div className="space-y-3">
                            {items.map((item, index) => (
                                <div key={`${item.title}-${index}`} className="flex justify-between gap-3 text-xs">
                                    <div className="min-w-0">
                                        <p className="font-black uppercase leading-tight">{item.title}</p>
                                        <p className="text-[10px] text-slate-400 mt-0.5">
                                            {Number(item.qty).toLocaleString("id-ID")} x {rp(item.price)}
                                            {item.unit ? ` / ${item.unit}` : ""}
                                        </p>
                                        {item.notes && (
                                            <p className="text-[10px] text-slate-500 italic mt-0.5">"{item.notes}"</p>
                                        )}
                                    </div>
                                    <span className="font-black shrink-0">{rp(item.total)}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* TOTAL */}
                    <div className="px-6 py-4 space-y-1.5 text-xs">
                        {totals.discount > 0 && (
                            <div className="flex justify-between text-emerald-600 font-black">
                                <span className="uppercase">Diskon</span>
                                <span>-{rp(totals.discount)}</span>
                            </div>
                        )}
                        {totals.tax > 0 && (
                            <div className="flex justify-between text-slate-500">
                                <span className="uppercase">Pajak {totals.tax_rate ? `${Number(totals.tax_rate)}%` : ""}</span>
                                <span>{rp(totals.tax)}</span>
                            </div>
                        )}
                        <div className="flex justify-between items-end border-t border-slate-200 dark:border-slate-700 pt-3 mt-3">
                            <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Total</span>
                            <span className="text-2xl font-black italic tracking-tighter text-slate-800 dark:text-white">
                                {rp(totals.total)}
                            </span>
                        </div>
                    </div>

                    <div className="px-6 py-4 text-center border-t border-dashed border-slate-200 dark:border-slate-700">
                        <p className="text-[10px] font-black uppercase italic tracking-wider text-slate-500">
                            {store?.footer || "Terima kasih"}
                        </p>
                        <p className="text-[9px] text-slate-300 dark:text-slate-600 mt-1.5">
                            Struk digital. Bukti resmi dicetak oleh kasir.
                        </p>
                    </div>
                </div>

                {/* CATATAN UNTUK PELANGGAN */}
                {paid && (
                    <p className="text-center text-[11px] text-slate-500 mt-4 leading-relaxed px-4">
                        Tunjukkan layar ini ke kasir. Kasir sudah tahu pembayaran
                        Anda masuk, jadi tidak perlu membayar lagi.
                    </p>
                )}

                {pending && (
                    <p className="text-center text-[11px] text-amber-700 mt-4 leading-relaxed px-4">
                        Belum membayar. Tutup halaman ini lalu tekan tombol
                        di bawah untuk kembali ke menu dan memindai QR lagi.
                    </p>
                )}

                {receipt.order_type === "dine_in" && receipt.table_name && (
                    <p className="text-center text-[11px] text-slate-500 mt-4 leading-relaxed px-4">
                        Pesanan untuk {receipt.table_name}.
                    </p>
                )}

                {failed && (
                    <p className="text-center text-[11px] text-rose-600 mt-4 leading-relaxed px-4">
                        Kode QR sudah tidak berlaku. Silakan bayar di kasir.
                    </p>
                )}
            </div>

            {/* TOMBOL AKSI */}
            <div className="fixed bottom-0 inset-x-0 p-4 bg-white/95 dark:bg-slate-900/95 backdrop-blur-xl border-t border-slate-200 dark:border-slate-800">
                <div className="max-w-md mx-auto flex gap-3">
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="flex-1 py-3.5 rounded-2xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-black uppercase text-[10px] tracking-widest active:scale-95 transition-all flex items-center justify-center gap-2"
                    >
                        <IconPrinter size={16} /> Simpan / Cetak
                    </button>
                    <Link
                        href={route("public.menu", receipt.order_type === "dine_in" ? { type: "table" } : { type: "takeaway" })}
                        className="flex-1 py-3.5 rounded-2xl border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 font-black uppercase text-[10px] tracking-widest active:scale-95 transition-all flex items-center justify-center"
                    >
                        Menu
                    </Link>
                </div>
            </div>

            <style>{`
                @media print {
                    body { background: white !important; }
                    .fixed { display: none !important; }
                    @page { margin: 10mm; }
                }
            `}</style>
        </div>
    );
}
