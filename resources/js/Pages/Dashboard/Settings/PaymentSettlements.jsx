import React, { useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconAlertTriangle,
    IconCheck,
    IconClock,
    IconClockOff,
    IconCreditCard,
    IconRefresh,
    IconSearch,
    IconX,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

const currency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value || 0));

const dateTime = (value) => {
    if (!value) return "-";
    try {
        return new Date(value).toLocaleString("id-ID", {
            day: "2-digit",
            month: "short",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        });
    } catch {
        return value;
    }
};

const statusStyle = {
    pending: "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300",
    paid: "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300",
    failed: "bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300",
    refunded: "bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300",
    expired: "bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300",
    unknown: "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300",
};

function SummaryCard({ label, value, icon: Icon, tone }) {
    return (
        <div className={`rounded-2xl border p-5 shadow-sm ${tone}`}>
            <div className="flex items-center justify-between">
                <span className="text-[10px] font-black uppercase tracking-widest opacity-70">{label}</span>
                <Icon size={22} strokeWidth={1.5} />
            </div>
            <p className="mt-3 text-3xl font-black">{value}</p>
        </div>
    );
}

export default function PaymentSettlements({
    settlements = { data: [], links: [] },
    events = [],
    summary = {},
    filters = {},
    statuses = [],
    providers = [],
}) {
    const { flash } = usePage().props;
    const [search, setSearch] = useState(filters.search || "");

    const applyFilters = (event) => {
        event.preventDefault();
        const params = {};
        if (filters.status) params.status = filters.status;
        if (filters.provider) params.provider = filters.provider;
        if (search) params.search = search;
        router.get(route("settings.payment-settlements.index"), params, { preserveState: true });
    };

    const reconcile = (settlement) => {
        router.post(route("settings.payment-settlements.reconcile-one", settlement.id), {}, {
            preserveScroll: true,
            onSuccess: () => toast.success("Status settlement diperbarui."),
            onError: () => toast.error("Reconciliation gagal."),
        });
    };

    const reconcileAll = () => {
        router.post(route("settings.payment-settlements.reconcile"), {}, {
            preserveScroll: true,
            onSuccess: () => toast.success("Reconciliation batch selesai."),
            onError: () => toast.error("Reconciliation batch gagal."),
        });
    };

    return (
        <>
            <Head title="Payment Settlement Center | LakuPOS" />

            <div className="space-y-6 pb-10">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-black text-slate-900 dark:text-white">
                            <IconCreditCard size={28} className="text-primary-500" />
                            Payment Settlement Center
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Monitor callback, settlement, retry, dan audit trail Midtrans/Xendit.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={reconcileAll}
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-primary-500/20 hover:bg-primary-700"
                    >
                        <IconRefresh size={18} />
                        Reconcile Pending
                    </button>
                </div>

                {flash?.success && <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{flash.success}</div>}
                {flash?.error && <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{flash.error}</div>}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <SummaryCard label="Total" value={summary.total || 0} icon={IconCreditCard} tone="border-slate-200 bg-white text-slate-800 dark:border-slate-800 dark:bg-slate-900 dark:text-white" />
                    <SummaryCard label="Pending" value={summary.pending || 0} icon={IconClock} tone="border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200" />
                    <SummaryCard label="Settled" value={summary.paid || 0} icon={IconCheck} tone="border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200" />
                    <SummaryCard label="Gagal" value={summary.failed || 0} icon={IconX} tone="border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200" />
                    <SummaryCard label="Menggantung" value={summary.expired || 0} icon={IconClockOff} tone="border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-200" />
                    <SummaryCard label="Perlu attention" value={summary.attention || 0} icon={IconAlertTriangle} tone="border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-200" />
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <form onSubmit={applyFilters} className="grid gap-3 md:grid-cols-[1fr_180px_180px_auto]">
                        <div className="relative">
                            <IconSearch size={17} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Cari invoice, customer, atau reference..."
                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm outline-none focus:border-primary-500 dark:border-slate-700 dark:bg-slate-800"
                            />
                        </div>
                        <select value={filters.provider || ""} onChange={(event) => router.get(route("settings.payment-settlements.index"), { ...filters, provider: event.target.value, search }, { preserveState: true })} className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="">Semua provider</option>
                            {providers.map((provider) => <option key={provider.value} value={provider.value}>{provider.label}</option>)}
                        </select>
                        <select value={filters.status || ""} onChange={(event) => router.get(route("settings.payment-settlements.index"), { ...filters, status: event.target.value, search }, { preserveState: true })} className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="">Semua status</option>
                            {statuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
                        </select>
                        <button type="submit" className="h-11 rounded-xl bg-slate-900 px-5 text-sm font-bold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900">Filter</button>
                    </form>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[980px] text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-[10px] font-black uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:bg-slate-800/60">
                                <tr>
                                    <th className="px-5 py-4">Invoice</th>
                                    <th className="px-5 py-4">Provider</th>
                                    <th className="px-5 py-4">Status</th>
                                    <th className="px-5 py-4">Nominal</th>
                                    <th className="px-5 py-4">Attempts</th>
                                    <th className="px-5 py-4">Last checked</th>
                                    <th className="px-5 py-4 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {settlements.data?.map((settlement) => (
                                    <tr key={settlement.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                        <td className="px-5 py-4">
                                            <p className="font-bold text-slate-800 dark:text-slate-100">{settlement.transaction?.invoice || '-'}</p>
                                            <p className="mt-1 text-[10px] text-slate-400">{settlement.transaction?.customer_name || 'Pelanggan'}</p>
                                        </td>
                                        <td className="px-5 py-4">
                                            <p className="font-semibold uppercase">{settlement.provider}</p>
                                            <p className="mt-1 max-w-[180px] truncate text-[10px] text-slate-400">{settlement.provider_reference || '-'}</p>
                                        </td>
                                        <td className="px-5 py-4">
                                            <span className={`rounded-full px-2.5 py-1 text-[10px] font-black uppercase ${statusStyle[settlement.status] || statusStyle.unknown}`}>{settlement.status}</span>
                                            {settlement.last_error && <p className="mt-1 max-w-[220px] truncate text-[10px] text-rose-500" title={settlement.last_error}>{settlement.last_error}</p>}
                                        </td>
                                        <td className="px-5 py-4 font-bold">{currency(settlement.settled_amount || settlement.amount)}</td>
                                        <td className="px-5 py-4">{settlement.attempt_count || 0}</td>
                                        <td className="px-5 py-4 text-xs text-slate-500">{dateTime(settlement.last_checked_at)}</td>
                                        <td className="px-5 py-4 text-right">
                                            {['pending', 'unknown', 'expired'].includes(settlement.status) && (
                                                <button type="button" onClick={() => reconcile(settlement)} title={settlement.status === 'expired' ? 'Periksa ulang ke provider (tidak otomatis lagi)' : undefined} className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-xs font-bold text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-950/30">
                                                    <IconRefresh size={14} /> {settlement.status === 'expired' ? 'Cek Ulang' : 'Cek Status'}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {(!settlements.data || settlements.data.length === 0) && (
                                    <tr><td colSpan="7" className="px-5 py-12 text-center text-sm text-slate-400">Belum ada data settlement.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    {settlements.links?.length > 0 && <div className="flex flex-wrap gap-2 border-t border-slate-200 px-5 py-4 dark:border-slate-800">{settlements.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded-lg px-3 py-2 text-xs font-bold ${link.active ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded-lg px-3 py-2 text-xs text-slate-300" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="text-sm font-black uppercase tracking-wider text-slate-700 dark:text-slate-200">Recent webhook events</h2>
                    <div className="mt-4 space-y-2">
                        {events.length === 0 && <p className="text-sm text-slate-400">Belum ada event.</p>}
                        {events.map((event) => (
                            <div key={event.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-4 py-3 text-xs dark:bg-slate-800/70">
                                <div><span className="font-bold uppercase">{event.provider}</span><span className="ml-2 text-slate-500">{event.transaction?.invoice || event.provider_reference || '-'}</span></div>
                                <div className="flex items-center gap-3 text-slate-500"><span className={event.signature_valid ? 'text-emerald-600' : 'text-rose-600'}>{event.signature_valid ? 'signature valid' : 'signature rejected'}</span><span>{event.status}</span><span>{dateTime(event.received_at)}</span></div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}

PaymentSettlements.layout = (page) => <DashboardLayout children={page} />;
