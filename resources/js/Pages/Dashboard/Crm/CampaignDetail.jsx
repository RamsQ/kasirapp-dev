import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Dashboard/Pagination";
import {
    IconArrowLeft,
    IconBellRinging,
    IconCheck,
    IconCopy,
    IconEye,
    IconPlayerPlay,
    IconSearch,
} from "@tabler/icons-react";
import { formatDateTime } from "@/Utils/DateTime";
import toast from "react-hot-toast";

const statusClass = {
    draft: "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300",
    scheduled: "bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300",
    processing: "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300",
    previewed: "bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300",
    sent: "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300",
    preview: "bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300",
    skipped: "bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400",
    partial: "bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300",
    failed: "bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300",
};

const statusLabel = {
    draft: "Draft",
    scheduled: "Terjadwal",
    processing: "Diproses",
    previewed: "Sudah Preview",
    sent: "Terkirim",
    preview: "Preview",
    skipped: "Dilewati",
    partial: "Sebagian Gagal",
    failed: "Gagal",
};

// Pembungkus dari DateTime.js yang mengembalikan nilai mentah kalau format
// gagal. `sample()` mengembalikan `scheduled_at` yang bisa null, dan kolom
// tabel ini harus tetap menampilkan sesuatu daripada kosong.
const waktuSingkat = (value) => {
    if (!value) return "-";
    try {
        return formatDateTime(value);
    } catch {
        return value;
    }
};

export default function CampaignDetail({ campaign, sample = [], runBlocker = null, deliveries, statusCounts = {}, filters = {} }) {
    const [search, setSearch] = useState(filters.search || "");
    const [status, setStatus] = useState(filters.status || "");

    const applyFilters = (overrides = {}) => {
        router.get(
            route("crm.campaigns.show", campaign.id),
            {
                status: overrides.status ?? status,
                search: overrides.search ?? search,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const copyMessage = async (message) => {
        try {
            await navigator.clipboard.writeText(message);
            toast.success("Pesan disalin.");
        } catch {
            toast.error("Gagal menyalin pesan.");
        }
    };

    const runCampaign = () => {
        if (!window.confirm(`Proses campaign "${campaign.name}"?`)) return;
        router.post(
            route("crm.campaigns.run", campaign.id),
            { force: true },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success("Campaign selesai diproses.");
                    router.reload({ only: ["deliveries", "statusCounts", "campaign"] });
                },
                onError: () => toast.error("Campaign gagal diproses."),
            }
        );
    };

    const hasDeliveries = deliveries?.data?.length > 0;

    return (
        <>
            <Head title={`${campaign.name} - CRM LakuPOS`} />
            <div className="space-y-6 pb-12">
                <Link
                    href={route("crm.campaigns.index")}
                    className="inline-flex items-center gap-1 text-xs font-black text-primary-600 dark:text-primary-400 hover:underline"
                >
                    <IconArrowLeft size={14} /> Kembali ke CRM Campaigns
                </Link>

                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="flex items-center gap-2 text-2xl font-black text-slate-900 dark:text-white">
                                <IconBellRinging className="text-pink-500" size={26} /> {campaign.name}
                            </h1>
                            <span className={`rounded-full px-2.5 py-1 text-[9px] font-black uppercase ${statusClass[campaign.status] || statusClass.draft}`}>
                                {statusLabel[campaign.status] || campaign.status}
                            </span>
                        </div>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {campaign.segment?.name || "Tanpa segment"} · channel {campaign.channel} ·{" "}
                            {campaign.is_dry_run ? "mode preview" : "mode live"}
                            {campaign.scheduled_at ? ` · jadwal ${waktuSingkat(campaign.scheduled_at)}` : ""}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={runCampaign}
                        disabled={!!runBlocker}
                        title={runBlocker || undefined}
                        className="inline-flex items-center justify-center gap-2 rounded-lg bg-pink-600 px-4 py-2.5 text-xs font-black text-white hover:bg-pink-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <IconPlayerPlay size={15} /> Proses
                    </button>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {["sent", "preview", "skipped", "failed"].map((key) => (
                        <div
                            key={key}
                            className={`rounded-2xl p-4 ${statusClass[key]} dark:bg-slate-900`}
                        >
                            <p className="text-[10px] font-black uppercase tracking-wider">
                                {statusLabel[key]}
                            </p>
                            <p className="mt-2 text-2xl font-black">{statusCounts[key] || 0}</p>
                        </div>
                    ))}
                    <div className="rounded-2xl bg-slate-100 p-4 text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        <p className="text-[10px] font-black uppercase tracking-wider">Total penerima</p>
                        <p className="mt-2 text-2xl font-black">{campaign.total_recipients || 0}</p>
                    </div>
                </div>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="flex items-center gap-2 text-sm font-black uppercase tracking-wider">
                                <IconEye size={18} className="text-indigo-500" /> Contoh Pesan Personal
                            </h2>
                            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {sample.length} penerima pertama dari segment. Halaman ini tidak mengirim apa pun
                                dan tidak mengubah data.
                            </p>
                        </div>
                        <span className="rounded-full bg-indigo-100 px-3 py-1 text-[10px] font-black uppercase text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                            Live Preview
                        </span>
                    </div>

                    {sample.length === 0 ? (
                        <p className="mt-5 rounded-xl bg-amber-50 p-4 text-xs font-bold text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                            {runBlocker
                                ? runBlocker
                                : 'Segment ini tidak punya penerima yang lolos filter. Periksa lagi filter segment (tier, transaksi, spend) atau lihat kartu Opt-out di halaman utama.'}
                        </p>
                    ) : (
                        <div className="mt-5 space-y-3">
                            {sample.map((row) => (
                                <div
                                    key={row.customer.id}
                                    className="rounded-xl border border-slate-100 p-4 dark:border-slate-800"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p className="text-sm font-black">{row.customer.name}</p>
                                            <p className="mt-0.5 text-[11px] text-slate-400">
                                                {row.customer.loyalty_tier || "bronze"} ·{" "}
                                                {row.customer.loyalty_points || 0} poin ·{" "}
                                                {row.target || "tanpa kontak"}
                                            </p>
                                        </div>
                                        <span
                                            className={`rounded-full px-2 py-1 text-[9px] font-black uppercase ${
                                                row.deliverable
                                                    ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                                                    : "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                                            }`}
                                        >
                                            {row.deliverable ? "Akan dikirim" : "Dilewati"}
                                        </span>
                                    </div>

                                    {row.reason && (
                                        <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-[11px] font-bold text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                            {row.reason}
                                        </p>
                                    )}

                                    {campaign.subject && (
                                        <p className="mt-2 text-[11px] text-slate-500 dark:text-slate-400">
                                            <span className="font-black">Subject:</span> {campaign.subject}
                                        </p>
                                    )}

                                    {campaign.voucher_mode !== "none" && (
                                        <p className="mt-2 rounded-lg bg-pink-50 px-3 py-2 text-[11px] font-bold text-pink-700 dark:bg-pink-950/40 dark:text-pink-300">
                                            {campaign.voucher_mode === "shared"
                                                ? `Kode promo bersama: ${campaign.shared_voucher_code} — sama untuk semua penerima.`
                                                : "Kode unik per penerima — tiap orang mendapat kode berbeda."}
                                        </p>
                                    )}

                                    <div className="mt-3 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed whitespace-pre-line text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                        {row.message}
                                    </div>

                                    <button
                                        type="button"
                                        onClick={() => copyMessage(row.message)}
                                        className="mt-2 inline-flex items-center gap-1 text-[10px] font-black text-primary-600 dark:text-primary-400 hover:underline"
                                    >
                                        <IconCopy size={12} /> Salin pesan
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="text-sm font-black uppercase tracking-wider">Riwayat Pengiriman</h2>
                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Semua hasil proses campaign beserta pesan yang benar-benar dikirim.
                    </p>

                    <div className="mt-4 flex flex-wrap items-end gap-3">
                        <div className="relative flex-1 min-w-[220px]">
                            <IconSearch
                                size={16}
                                className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
                            />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === "Enter" && applyFilters()}
                                placeholder="Cari nama pelanggan atau target..."
                                className="h-10 w-full rounded-lg border border-slate-200 bg-white pl-9 pr-3 text-sm dark:border-slate-700 dark:bg-slate-900"
                            />
                        </div>
                        <select
                            value={status}
                            onChange={(e) => {
                                setStatus(e.target.value);
                                applyFilters({ status: e.target.value });
                            }}
                            className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"
                        >
                            <option value="">Semua status</option>
                            {["sent", "preview", "skipped", "failed"].map((key) => (
                                <option key={key} value={key}>
                                    {statusLabel[key]}
                                </option>
                            ))}
                        </select>
                        <button
                            type="button"
                            onClick={() => applyFilters()}
                            className="inline-flex h-10 items-center gap-1 rounded-lg bg-primary-600 px-4 text-xs font-black text-white"
                        >
                            <IconCheck size={14} /> Terapkan
                        </button>
                    </div>

                    {!hasDeliveries ? (
                        <p className="mt-5 rounded-xl bg-slate-50 p-4 text-xs text-slate-500 dark:text-slate-400 dark:bg-slate-800">
                            Belum ada riwayat. Klik tombol Proses untuk menjalankan campaign.
                        </p>
                    ) : (
                        <>
                            <div className="mt-5 space-y-2">
                                {deliveries.data.map((delivery) => (
                                    <div
                                        key={delivery.id}
                                        className="rounded-xl border border-slate-100 p-4 text-xs dark:border-slate-800"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <p className="font-black">
                                                    {delivery.customer?.name || "Pelanggan dihapus"}
                                                </p>
                                                <p className="mt-0.5 text-[11px] text-slate-400">
                                                    {delivery.target || "tanpa kontak"} ·{" "}
                                                    {waktuSingkat(delivery.sent_at || delivery.created_at)}
                                                </p>
                                            </div>
                                            <span
                                                className={`rounded-full px-2 py-1 text-[9px] font-black uppercase ${
                                                    statusClass[delivery.status] || statusClass.draft
                                                }`}
                                            >
                                                {statusLabel[delivery.status] || delivery.status}
                                            </span>
                                        </div>

                                        {delivery.error && (
                                            <p className="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-[11px] font-bold text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">
                                                {delivery.error}
                                            </p>
                                        )}

                                        <details className="mt-2">
                                            <summary className="cursor-pointer text-[11px] font-black text-primary-600 dark:text-primary-400">
                                                Lihat pesan
                                            </summary>
                                            <div className="mt-2 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed whitespace-pre-line text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                                {delivery.message}
                                            </div>
                                        </details>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-5">
                                <Pagination links={deliveries.links} />
                            </div>
                        </>
                    )}
                </section>
            </div>
        </>
    );
}

CampaignDetail.layout = (page) => <DashboardLayout children={page} />;
