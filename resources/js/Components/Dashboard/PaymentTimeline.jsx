import React from "react";
import {
    IconCheck,
    IconClock,
    IconX,
    IconAlertTriangle,
    IconShieldCheck,
    IconShieldX,
    IconClockHour4,
} from "@tabler/icons-react";
import { formatDateTime } from "@/Utils/DateTime";

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
    return formatDateTime(d, {
        day: "2-digit",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    });
};

const TONE = {
    success: {
        dot: "bg-emerald-500",
        chip: "bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800",
        line: "bg-emerald-200 dark:bg-emerald-900",
        Icon: IconCheck,
    },
    pending: {
        dot: "bg-amber-500",
        chip: "bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800",
        line: "bg-amber-200 dark:bg-amber-900",
        Icon: IconClock,
    },
    failed: {
        dot: "bg-rose-500",
        chip: "bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800",
        line: "bg-rose-200 dark:bg-rose-900",
        Icon: IconX,
    },
    neutral: {
        dot: "bg-slate-400",
        chip: "bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700",
        line: "bg-slate-200 dark:bg-slate-700",
        Icon: IconClockHour4,
    },
};

/**
 * Riwayat pembayaran satu transaksi.
 *
 * Data-nya datang dari `payment_events` — tabel yang sama dipakai
 * `PaymentSettlementService` saat rekonsiliasi. Jadi angka yang tampil di sini
 * tidak mungkin berbeda dengan yang dipakai sistem untuk memutuskan transaksi
 * itu lunas atau belum.
 *
 * Yang ditampilkan:
 *  - status tiap callback, beserta jamnya
 *  - nominal yang dilaporkan gateway pada saat itu
 *  - apakah tanda tangannya valid (null = event dari sisi server, bukan callback)
 *  - alasan kalau ada yang ditolak
 *
 * Tanda tangan tidak valid ditampilkan mencolok karena itu satu-satunya tanda
 * bahwa ada yang mencoba menyamar sebagai gateway.
 */
export default function PaymentTimeline({ transaction }) {
    const events = transaction.payment_timeline ?? [];
    const summary = transaction.payment_summary ?? null;

    if (!events.length) {
        return (
            <p className="text-xs text-slate-500 dark:text-slate-400 font-bold italic">
                Tidak ada riwayat pembayaran gateway untuk transaksi ini.
            </p>
        );
    }

    return (
        <div className="space-y-4">
            {summary && (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-[9px] font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">
                        Ringkasan
                    </span>
                    <span className="px-2.5 py-1 rounded-lg bg-slate-900 text-white dark:bg-slate-800 text-[9px] font-black uppercase tracking-wider">
                        {summary.provider_label}
                    </span>
                    <span
                        className={`px-2.5 py-1 rounded-lg border text-[9px] font-black uppercase tracking-wider ${
                            (TONE[summary.tone] ?? TONE.neutral).chip
                        }`}
                    >
                        {summary.status_label}
                    </span>
                    {Number(summary.attempts) > 1 && (
                        <span className="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 text-[9px] font-black uppercase tracking-wider">
                            {summary.attempts} kali callback
                        </span>
                    )}
                    {summary.settled_at && (
                        <span className="text-[9px] font-bold text-slate-500 dark:text-slate-400">
                            Lunas {waktu(summary.settled_at)}
                        </span>
                    )}
                </div>
            )}

            <ol className="relative space-y-3 pl-6">
                {events.map((event, index) => {
                    const tone = TONE[event.tone] ?? TONE.neutral;
                    const { Icon } = tone;
                    const isLast = index === events.length - 1;
                    // null = event dibuat di sisi server, jadi tidak ada tanda
                    // tangan yang perlu diverifikasi. Menampilkannya sebagai
                    // "tidak valid" akan membuat kasir curiga tanpa alasan.
                    const signatureProblem = event.signature_valid === false;

                    return (
                        <li key={event.id} className="relative">
                            {!isLast && (
                                <span className={`absolute left-[-15px] top-6 h-full w-0.5 ${tone.line}`} />
                            )}
                            <span
                                className={`absolute -left-[23px] top-1 w-3 h-3 rounded-full border-2 border-white dark:border-slate-950 ${tone.dot}`}
                            />

                            <div className="flex flex-wrap items-center gap-2">
                                <span
                                    className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border text-[9px] font-black uppercase tracking-wider ${tone.chip}`}
                                >
                                    <Icon size={11} stroke={3} />
                                    {event.status_label}
                                </span>
                                <span className="text-[9px] font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">
                                    {event.provider_label}
                                </span>
                                <span className="text-[10px] font-bold text-slate-500 dark:text-slate-400">
                                    {waktu(event.received_at)}
                                </span>
                                {event.amount !== null && (
                                    <span className="text-[10px] font-black text-slate-700 dark:text-slate-200">
                                        {rp(event.amount)}
                                    </span>
                                )}
                                {!event.processed && !signatureProblem && (
                                    <span className="px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 text-[8px] font-black uppercase tracking-wider">
                                        Belum diproses
                                    </span>
                                )}
                                {signatureProblem ? (
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-rose-100 dark:bg-rose-950/50 text-rose-700 dark:text-rose-300 text-[8px] font-black uppercase tracking-wider border border-rose-300 dark:border-rose-800">
                                        <IconShieldX size={11} /> Tanda tangan tidak valid
                                    </span>
                                ) : event.signature_valid === true ? (
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 text-[8px] font-black uppercase tracking-wider">
                                        <IconShieldCheck size={11} /> Terverifikasi
                                    </span>
                                ) : null}
                            </div>

                            {event.error && (
                                <p className="mt-1.5 flex items-start gap-1.5 text-[10px] font-bold text-rose-600 dark:text-rose-400 leading-relaxed">
                                    <IconAlertTriangle size={12} className="shrink-0 mt-0.5" />
                                    {event.error}
                                </p>
                            )}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}
