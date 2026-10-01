import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconArrowLeft,
    IconCheck,
    IconGift,
    IconPencil,
    IconSettings,
    IconTrash,
    IconX,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

const emptyRule = {
    name: "",
    description: "",
    min_spend: "",
    points: "",
    product_id: "",
    category_id: "",
    starts_at: "",
    ends_at: "",
    priority: 0,
    is_active: true,
};

const currency = (value = 0) => new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
}).format(Number(value || 0));

const dateOnly = (value) => {
    if (!value) return "Selalu";
    try {
        return new Date(value).toLocaleDateString("id-ID", {
            day: "2-digit",
            month: "short",
            year: "numeric",
        });
    } catch {
        return value;
    }
};

export default function LoyaltyRules({ rules = [], products = [], categories = [], canManage = false, embedded = false }) {
    const [form, setForm] = useState(emptyRule);
    const [editing, setEditing] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const setField = (field, value) => setForm((current) => ({ ...current, [field]: value }));

    const resetForm = () => {
        setForm(emptyRule);
        setEditing(null);
        setErrors({});
    };

    const editRule = (rule) => {
        setEditing(rule.id);
        setErrors({});
        setForm({
            name: rule.name || "",
            description: rule.description || "",
            min_spend: rule.min_spend ?? 0,
            points: rule.points ?? 0,
            product_id: rule.product_id || "",
            category_id: rule.category_id || "",
            starts_at: rule.starts_at ? String(rule.starts_at).slice(0, 10) : "",
            ends_at: rule.ends_at ? String(rule.ends_at).slice(0, 10) : "",
            priority: rule.priority ?? 0,
            is_active: !!rule.is_active,
        });
        window.scrollTo({ top: 0, behavior: "smooth" });
    };

    const submit = (event) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(editing ? "Rule loyalty diperbarui." : "Rule loyalty dibuat.");
                resetForm();
            },
            onError: (validationErrors) => {
                setErrors(validationErrors);
                toast.error("Periksa kembali data rule.");
            },
            onFinish: () => setSaving(false),
        };

        if (editing) {
            router.put(route("discounts.loyalty-rules.update", editing), form, options);
        } else {
            router.post(route("discounts.loyalty-rules.store"), form, options);
        }
    };

    const deleteRule = (rule) => {
        if (!window.confirm(`Hapus rule "${rule.name}"?`)) return;
        router.delete(route("discounts.loyalty-rules.destroy", rule.id), {
            preserveScroll: true,
            onSuccess: () => toast.success("Rule loyalty dihapus."),
            onError: () => toast.error("Rule loyalty gagal dihapus."),
        });
    };

    const fieldError = (field) => errors[field] ? (
        <p className="mt-1 text-[10px] font-bold text-rose-500">{errors[field]}</p>
    ) : null;

    return (
        <>
            {!embedded && <Head title="Rule Loyalty | LakuPOS" />}
            <div className={embedded ? "space-y-6" : "space-y-6 pb-12"}>
                {!embedded && <div>
                    <Link href={route("settings.index")} className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600">
                        <IconArrowLeft size={16} /> Kembali ke System Settings
                    </Link>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-black text-slate-900 dark:text-white">
                                <IconGift size={28} className="text-primary-500" /> Rule Loyalty
                            </h1>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Admin menentukan sendiri syarat minimum belanja dan jumlah poin per transaksi.</p>
                        </div>
                        <span className="rounded-full bg-emerald-100 px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                            {rules.filter((rule) => rule.is_active).length} rule aktif
                        </span>
                    </div>
                </div>}

                <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200">
                    <p className="font-black uppercase tracking-wider">Cara sistem memilih rule</p>
                    <p className="mt-1 leading-relaxed">Satu transaksi hanya mendapatkan satu rule. Rule aktif dengan <strong>priority tertinggi</strong> dipilih terlebih dahulu; jika sama, rule dengan minimum belanja tertinggi, poin tertinggi, dan terbaru dipilih. Jika tidak ada rule yang cocok, sistem memakai pengaturan poin global sebagai fallback.</p>
                </div>

                {canManage ? (
                    <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <h2 className="flex items-center gap-2 text-sm font-black uppercase tracking-wider"><IconSettings size={18} className="text-primary-500" /> {editing ? "Edit Rule" : "Buat Rule Baru"}</h2>
                                <p className="mt-1 text-xs text-slate-500">Minimal belanja dihitung dari omzet setelah diskon/voucher dan sebelum pajak.</p>
                            </div>
                            {editing && <button type="button" onClick={resetForm} className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-xs font-black text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"><IconX size={15} /> Batal</button>}
                        </div>
                        <div className="mt-5 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            <label className="text-xs font-black text-slate-600 dark:text-slate-300">Nama Rule<input required value={form.name} onChange={(e) => setField("name", e.target.value)} placeholder="Contoh: Member Kopi" className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 font-normal dark:border-slate-700 dark:bg-slate-800" />{fieldError("name")}</label>
                            <label className="text-xs font-black text-slate-600 dark:text-slate-300">Minimum Belanja (Rp)<input required type="number" min="0" step="1" value={form.min_spend} onChange={(e) => setField("min_spend", e.target.value)} placeholder="50000" className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 font-normal dark:border-slate-700 dark:bg-slate-800" />{fieldError("min_spend")}</label>
                            <label className="text-xs font-black text-slate-600 dark:text-slate-300">Poin per Transaksi<input required type="number" min="0" step="1" value={form.points} onChange={(e) => setField("points", e.target.value)} placeholder="10" className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 font-normal dark:border-slate-700 dark:bg-slate-800" />{fieldError("points")}</label>
                            <label className="text-xs font-black text-slate-600 dark:text-slate-300">Produk (opsional)<select value={form.product_id} onChange={(e) => setField("product_id", e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 font-normal dark:border-slate-700 dark:bg-slate-800"><option value="">Semua produk</option>{products.map((product) => <option key={product.id} value={product.id}>{product.title}</option>)}</select>{fieldError("product_id")}</label>
                            <label className="text-xs font-black text-slate-600 dark:text-slate-300">Kategori (opsional)<select value={form.category_id} onChange={(e) => setField("category_id", e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 font-normal dark:border-slate-700 dark:bg-slate-800"><option value="">Semua kategori</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select>{fieldError("category_id")}</label>
                            <label className="text-xs font-black text-slate-600 dark:text-slate-300">Priority<input required type="number" min="0" max="1000" value={form.priority} onChange={(e) => setField("priority", e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 font-normal dark:border-slate-700 dark:bg-slate-800" />{fieldError("priority")}</label>
                            <label className="text-xs font-black text-slate-600 dark:text-slate-300">Mulai Berlaku<input type="date" value={form.starts_at} onChange={(e) => setField("starts_at", e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 font-normal dark:border-slate-700 dark:bg-slate-800" />{fieldError("starts_at")}</label>
                            <label className="text-xs font-black text-slate-600 dark:text-slate-300">Berakhir<input type="date" value={form.ends_at} onChange={(e) => setField("ends_at", e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 font-normal dark:border-slate-700 dark:bg-slate-800" />{fieldError("ends_at")}</label>
                            <label className="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" checked={form.is_active} onChange={(e) => setField("is_active", e.target.checked)} /> Rule aktif</label>
                            <label className="text-xs font-black text-slate-600 dark:text-slate-300 md:col-span-2 lg:col-span-3">Catatan<textarea value={form.description} onChange={(e) => setField("description", e.target.value)} rows={2} placeholder="Penjelasan singkat rule" className="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 font-normal dark:border-slate-700 dark:bg-slate-800" />{fieldError("description")}</label>
                        </div>
                        <button disabled={saving} className="mt-5 inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-3 text-xs font-black uppercase tracking-wider text-white disabled:opacity-50"><IconCheck size={16} /> {saving ? "Menyimpan..." : editing ? "Update Rule" : "Simpan Rule"}</button>
                    </form>
                ) : (
                    <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">Anda hanya dapat melihat rule. Hubungi administrator dengan permission settings.update atau settings.cgos untuk mengubah rule.</div>
                )}

                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center justify-between"><div><h2 className="text-sm font-black uppercase tracking-wider">Daftar Rule</h2><p className="mt-1 text-xs text-slate-500">Rule yang sedang aktif dan riwayat konfigurasi.</p></div><span className="text-xs font-bold text-slate-400">{rules.length} total</span></div>
                    <div className="mt-4 space-y-3">
                        {rules.length === 0 && <p className="py-8 text-center text-sm text-slate-400">Belum ada rule loyalty.</p>}
                        {rules.map((rule) => (
                            <div key={rule.id} className="rounded-xl border border-slate-100 p-4 dark:border-slate-800">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div><div className="flex flex-wrap items-center gap-2"><h3 className="font-black text-slate-800 dark:text-white">{rule.name}</h3><span className={`rounded-full px-2 py-1 text-[9px] font-black uppercase ${rule.is_active ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300" : "bg-slate-100 text-slate-500 dark:bg-slate-800"}`}>{rule.is_active ? "Aktif" : "Nonaktif"}</span><span className="rounded-full bg-indigo-100 px-2 py-1 text-[9px] font-black uppercase text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">Priority {rule.priority}</span></div><p className="mt-1 text-xs text-slate-500">{rule.description || "Tanpa catatan"}</p></div>
                                    {canManage && <div className="flex gap-2"><button type="button" onClick={() => editRule(rule)} className="rounded-lg bg-amber-100 p-2 text-amber-700 hover:bg-amber-200 dark:bg-amber-950 dark:text-amber-300" title="Edit"><IconPencil size={16} /></button><button type="button" onClick={() => deleteRule(rule)} className="rounded-lg bg-rose-100 p-2 text-rose-700 hover:bg-rose-200 dark:bg-rose-950 dark:text-rose-300" title="Hapus"><IconTrash size={16} /></button></div>}
                                </div>
                                <div className="mt-3 flex flex-wrap gap-2 text-[10px] font-bold text-slate-500"><span className="rounded-lg bg-slate-100 px-2 py-1 dark:bg-slate-800">Min {currency(rule.min_spend)}</span><span className="rounded-lg bg-emerald-100 px-2 py-1 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">{rule.points} poin</span>{rule.product && <span className="rounded-lg bg-blue-100 px-2 py-1 text-blue-700 dark:bg-blue-950 dark:text-blue-300">Produk: {rule.product.title}</span>}{rule.category && <span className="rounded-lg bg-purple-100 px-2 py-1 text-purple-700 dark:bg-purple-950 dark:text-purple-300">Kategori: {rule.category.name}</span>}<span className="rounded-lg bg-slate-100 px-2 py-1 dark:bg-slate-800">{dateOnly(rule.starts_at)} — {dateOnly(rule.ends_at)}</span></div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}

LoyaltyRules.layout = (page) => <DashboardLayout children={page} />;
