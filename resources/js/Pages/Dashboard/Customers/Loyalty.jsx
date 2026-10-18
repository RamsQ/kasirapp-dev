import React, { useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconArrowLeft,
    IconGift,
    IconHistory,
    IconPlus,
    IconRosetteDiscount,
    IconStar,
    IconUser,
} from "@tabler/icons-react";
import { formatDate } from "@/Utils/DateTime";
import toast from "react-hot-toast";

const currency = (value = 0) => new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
}).format(Number(value || 0));

const dateOnly = (value) => {
    if (!value) return "-";
    try { return formatDate(value, { day: "2-digit", month: "short", year: "numeric" }); }
    catch { return value; }
};

const tierClass = {
    bronze: "from-amber-700 to-amber-500",
    silver: "from-slate-500 to-slate-300",
    gold: "from-yellow-600 to-yellow-300",
    platinum: "from-indigo-600 to-cyan-400",
};

export default function Loyalty({ customer, summary, canManageLoyalty = false }) {
    const [points, setPoints] = useState({ points: "", description: "" });
    const [voucher, setVoucher] = useState({ code: "", name: "", type: "fixed", value: "", min_spend: "", expires_at: "" });
    const [savingPoints, setSavingPoints] = useState(false);
    const [savingVoucher, setSavingVoucher] = useState(false);

    const submitPoints = (event) => {
        event.preventDefault();
        setSavingPoints(true);
        router.post(route("customers.loyalty.adjust", customer.id), points, {
            preserveScroll: true,
            onSuccess: () => { toast.success("Poin pelanggan diperbarui."); setPoints({ points: "", description: "" }); },
            onError: () => toast.error("Poin gagal diperbarui."),
            onFinish: () => setSavingPoints(false),
        });
    };

    const submitVoucher = (event) => {
        event.preventDefault();
        setSavingVoucher(true);
        router.post(route("customers.vouchers.store", customer.id), voucher, {
            preserveScroll: true,
            onSuccess: () => { toast.success("Voucher dibuat."); setVoucher({ code: "", name: "", type: "fixed", value: "", min_spend: "", expires_at: "" }); },
            onError: () => toast.error("Voucher gagal dibuat."),
            onFinish: () => setSavingVoucher(false),
        });
    };

    return (
        <>
            <Head title={`Loyalty ${customer.name} | LakuPOS`} />
            <div className="space-y-6 pb-10">
                <div>
                    <Link href={route("customers.index")} className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600"><IconArrowLeft size={16} /> Kembali ke Pelanggan</Link>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-black text-slate-900 dark:text-white"><IconUser size={28} className="text-primary-500" /> {customer.name}</h1>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Loyalty & CRM Center · {customer.no_telp || '-'}</p>
                        </div>
                        <span className={`rounded-full bg-gradient-to-r px-4 py-2 text-xs font-black uppercase text-white shadow-lg ${tierClass[summary.tier?.name] || tierClass.bronze}`}>{summary.tier?.label || 'Bronze'}</span>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-4">
                    <div className="rounded-2xl bg-white p-5 shadow-sm dark:bg-slate-900 dark:border dark:border-slate-800"><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Poin tersedia</p><p className="mt-2 text-3xl font-black text-primary-600">{customer.loyalty_points || 0}</p></div>
                    <div className="rounded-2xl bg-white p-5 shadow-sm dark:bg-slate-900 dark:border dark:border-slate-800"><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Lifetime spend</p><p className="mt-2 text-xl font-black">{currency(summary.total_spent)}</p></div>
                    <div className="rounded-2xl bg-white p-5 shadow-sm dark:bg-slate-900 dark:border dark:border-slate-800"><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Transaksi</p><p className="mt-2 text-3xl font-black">{summary.total_transactions}</p></div>
                    <div className="rounded-2xl bg-white p-5 shadow-sm dark:bg-slate-900 dark:border dark:border-slate-800"><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Kunjungan terakhir</p><p className="mt-2 text-sm font-black">{dateOnly(summary.last_visit)}</p></div>
                </div>

                {canManageLoyalty && (
                    <div className="flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs dark:border-amber-900 dark:bg-amber-950/30 sm:flex-row sm:items-center sm:justify-between">
                        <div><p className="font-black uppercase tracking-wider text-amber-800 dark:text-amber-200">Status komunikasi CRM</p><p className="mt-1 text-amber-700 dark:text-amber-300">{customer.crm_opt_out_at ? `Opt-out sejak ${dateOnly(customer.crm_opt_out_at)}` : customer.marketing_opt_in ? 'Pelanggan mengizinkan promo/marketing.' : 'Pelanggan tidak mengizinkan promo/marketing.'}</p></div>
                        <button type="button" onClick={() => router.post(customer.crm_opt_out_at ? route("customers.crm.opt-in", customer.id) : route("customers.crm.opt-out", customer.id), {}, { preserveScroll: true, onSuccess: () => toast.success("Status komunikasi CRM diperbarui."), onError: () => toast.error("Status CRM gagal diperbarui.") })} className="rounded-lg bg-amber-600 px-3 py-2 font-black text-white hover:bg-amber-700">{customer.crm_opt_out_at ? "Aktifkan Lagi" : "Tandai Opt-Out"}</button>
                    </div>
                )}

                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Tanggal lahir</p><p className="mt-1 text-sm font-bold">{dateOnly(customer.birthday)}</p></div>
                        <div><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Marketing</p><p className="mt-1 text-sm font-bold">{customer.marketing_opt_in ? 'Diizinkan' : 'Tidak diizinkan'}</p></div>
                        <div><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Catatan CRM</p><p className="mt-1 line-clamp-2 text-sm font-bold">{customer.notes || '-'}</p></div>
                    </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center justify-between"><div><h2 className="text-sm font-black uppercase tracking-wider">Progress tier</h2><p className="mt-1 text-xs text-slate-500">{summary.next_tier ? `${summary.next_tier.minimum - customer.loyalty_points} poin lagi menuju ${summary.next_tier.label}` : 'Tier tertinggi tercapai'}</p></div><IconStar size={22} className="text-amber-500" /></div>
                    <div className="mt-4 h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div className="h-full rounded-full bg-gradient-to-r from-primary-500 to-amber-400" style={{ width: `${summary.progress}%` }} /></div>
                    <p className="mt-2 text-right text-[10px] font-black text-slate-400">{summary.progress}%</p>
                </div>

                {!canManageLoyalty && <p className="rounded-xl bg-slate-100 px-4 py-3 text-xs font-bold text-slate-500 dark:bg-slate-800 dark:text-slate-300">Anda memiliki akses melihat loyalty. Hubungi administrator dengan izin customers-edit untuk menyesuaikan poin atau menerbitkan voucher.</p>}
                <fieldset disabled={!canManageLoyalty} className="grid gap-6 lg:grid-cols-2">
                    <form onSubmit={submitPoints} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="flex items-center gap-2 text-sm font-black uppercase tracking-wider"><IconHistory size={18} className="text-primary-500" /> Penyesuaian Poin</h2>
                        <p className="mt-1 text-xs text-slate-500">Poin manual tercatat di ledger dan dapat diaudit.</p>
                        <div className="mt-4 grid gap-3 sm:grid-cols-[130px_1fr]"><input type="number" required value={points.points} onChange={(e) => setPoints({ ...points, points: e.target.value })} placeholder="+/- poin" className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800" /><input required value={points.description} onChange={(e) => setPoints({ ...points, description: e.target.value })} placeholder="Alasan penyesuaian" className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800" /></div>
                        <button disabled={savingPoints} className="mt-4 inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 text-xs font-black text-white disabled:opacity-50"><IconPlus size={16} /> {savingPoints ? 'Menyimpan...' : 'Simpan Poin'}</button>
                    </form>

                    <form onSubmit={submitVoucher} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="flex items-center gap-2 text-sm font-black uppercase tracking-wider"><IconGift size={18} className="text-amber-500" /> Terbitkan Voucher</h2>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2"><input required value={voucher.code} onChange={(e) => setVoucher({ ...voucher, code: e.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '') })} placeholder="KODEVOUCHER" className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 uppercase dark:border-slate-700 dark:bg-slate-800" /><input required value={voucher.name} onChange={(e) => setVoucher({ ...voucher, name: e.target.value })} placeholder="Nama voucher" className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800" /><select value={voucher.type} onChange={(e) => setVoucher({ ...voucher, type: e.target.value })} className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800"><option value="fixed">Potongan Rp</option><option value="percentage">Persentase</option></select><input required type="number" min="0.01" value={voucher.value} onChange={(e) => setVoucher({ ...voucher, value: e.target.value })} placeholder="Nilai" className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800" /><input type="number" min="0" value={voucher.min_spend} onChange={(e) => setVoucher({ ...voucher, min_spend: e.target.value })} placeholder="Minimum belanja" className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800" /><input type="datetime-local" value={voucher.expires_at} onChange={(e) => setVoucher({ ...voucher, expires_at: e.target.value })} className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-800" /></div>
                        <button disabled={savingVoucher} className="mt-4 inline-flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2.5 text-xs font-black text-white disabled:opacity-50"><IconRosetteDiscount size={16} /> {savingVoucher ? 'Membuat...' : 'Buat Voucher'}</button>
                    </form>
                </fieldset>

                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h2 className="text-sm font-black uppercase tracking-wider">Riwayat Poin</h2><div className="mt-4 space-y-2">{summary.ledger.length === 0 && <p className="text-sm text-slate-400">Belum ada mutasi poin.</p>}{summary.ledger.map((entry) => <div key={entry.id} className="flex justify-between rounded-xl bg-slate-50 px-3 py-2 text-xs dark:bg-slate-800"><span>{entry.description || entry.type}</span><strong className={entry.points >= 0 ? 'text-emerald-600' : 'text-rose-600'}>{entry.points >= 0 ? '+' : ''}{entry.points}</strong></div>)}</div></div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h2 className="text-sm font-black uppercase tracking-wider">Voucher Pelanggan</h2><div className="mt-4 space-y-2">{summary.vouchers.length === 0 && <p className="text-sm text-slate-400">Belum ada voucher.</p>}{summary.vouchers.map((item) => <div key={item.id} className="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2 text-xs dark:bg-slate-800"><div><p className="font-black">{item.code}</p><p className="text-slate-400">{item.name}</p></div><span className={`rounded-full px-2 py-1 text-[9px] font-black uppercase ${item.status === 'active' ? 'bg-emerald-100 text-emerald-700' : item.status === 'used' ? 'bg-slate-200 text-slate-600' : 'bg-amber-100 text-amber-700'}`}>{item.status}</span></div>)}</div></div>
                </div>
            </div>
        </>
    );
}

Loyalty.layout = (page) => <DashboardLayout children={page} />;
