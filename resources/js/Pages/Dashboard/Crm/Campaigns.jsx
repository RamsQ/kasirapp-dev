import React, { useMemo, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconBellRinging,
    IconCheck,
    IconDeviceMobile,
    IconEye,
    IconPencil,
    IconPlayerPlay,
    IconTrash,
    IconUsers,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

const MONTHS = [
    "Januari", "Februari", "Maret", "April", "Mei", "Juni",
    "Juli", "Agustus", "September", "Oktober", "November", "Desember",
];

const emptySegment = {
    name: "",
    description: "",
    tiers: [],
    min_lifetime_spend: "",
    min_transactions: "",
    inactive_days: "",
    birthday_month: "",
    birthday_day: "",
    product_id: "",
    category_id: "",
    is_active: true,
};

const emptyCampaign = {
    name: "",
    segment_id: "",
    type: "manual",
    channel: "copy",
    subject: "",
    message: "Halo {name}, ada promo spesial untuk Anda.",
    scheduled_at: "",
    is_dry_run: true,
    voucher_mode: "none",
    voucher_name: "",
    voucher_type: "fixed",
    voucher_value: "",
    voucher_usage_limit: 1,
    voucher_min_spend: "",
    voucher_expires_at: "",
    voucher_prefix: "PROMO",
    shared_voucher_code: "",
};

// Tampilkan pesan error validasi yang sebenarnya, bukan pesan generik saja,
// supaya admin tahu field mana yang salah.
const firstErrorMessage = (errors, fallback) => {
    if (!errors) return fallback;
    const first = Object.values(errors)[0];
    return Array.isArray(first) ? first[0] : first || fallback;
};

const formatDate = (value) => {
    if (!value) return "-";
    try {
        return new Date(value).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" });
    } catch {
        return value;
    }
};

const statusClass = {
    draft: "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300",
    scheduled: "bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300",
    processing: "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300",
    previewed: "bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300",
    sent: "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300",
    partial: "bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300",
    failed: "bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300",
};

export default function Campaigns({ segments = [], campaigns = [], products = [], categories = [], canManage = false, stats = {} }) {
    const [segmentForm, setSegmentForm] = useState(emptySegment);
    const [editingSegment, setEditingSegment] = useState(null);
    const [campaignForm, setCampaignForm] = useState(emptyCampaign);
    const [editingCampaign, setEditingCampaign] = useState(null);
    const [savingSegment, setSavingSegment] = useState(false);
    const [savingCampaign, setSavingCampaign] = useState(false);
    const [testing, setTesting] = useState(false);
    const [testForm, setTestForm] = useState({ phone: "", channel: "whatsapp" });

    const setSegmentField = (field, value) => setSegmentForm((current) => ({ ...current, [field]: value }));
    const setCampaignField = (field, value) => setCampaignForm((current) => ({ ...current, [field]: value }));

    const editSegment = (segment) => {
        setEditingSegment(segment.id);
        setSegmentForm({
            ...emptySegment,
            name: segment.name || "",
            description: segment.description || "",
            tiers: segment.rules?.tiers || [],
            min_lifetime_spend: segment.rules?.min_lifetime_spend ?? "",
            min_transactions: segment.rules?.min_transactions ?? "",
            inactive_days: segment.rules?.inactive_days ?? "",
            birthday_month: segment.rules?.birthday_month ?? "",
            birthday_day: segment.rules?.birthday_day ?? "",
            product_id: segment.rules?.product_id ?? "",
            category_id: segment.rules?.category_id ?? "",
            is_active: !!segment.is_active,
        });
    };

    const resetSegment = () => {
        setEditingSegment(null);
        setSegmentForm(emptySegment);
    };

    // Field filter disimpan di dalam objek `rules` (kolom JSON di database),
    // bukan di root payload.
    const buildSegmentPayload = (form) => ({
        name: form.name,
        description: form.description || null,
        is_active: !!form.is_active,
        rules: {
            tiers: form.tiers || [],
            min_lifetime_spend: form.min_lifetime_spend,
            min_transactions: form.min_transactions,
            inactive_days: form.inactive_days,
            birthday_month: form.birthday_month,
            birthday_day: form.birthday_day,
            product_id: form.product_id,
            category_id: form.category_id,
        },
    });

    const submitSegment = (event) => {
        event.preventDefault();
        setSavingSegment(true);
        const payload = buildSegmentPayload(segmentForm);
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(editingSegment ? "Segment diperbarui." : "Segment dibuat.");
                resetSegment();
            },
            onError: (errors) => toast.error(firstErrorMessage(errors, "Segment gagal disimpan.")),
            onFinish: () => setSavingSegment(false),
        };
        if (editingSegment) {
            router.put(route("crm.segments.update", editingSegment), payload, options);
        } else {
            router.post(route("crm.segments.store"), payload, options);
        }
    };

    const editCampaign = (campaign) => {
        setEditingCampaign(campaign.id);
        setCampaignForm({
            ...emptyCampaign,
            name: campaign.name || "",
            segment_id: campaign.segment_id || "",
            type: campaign.type || "manual",
            channel: campaign.channel || "copy",
            subject: campaign.subject || "",
            message: campaign.message || "",
            voucher_mode: campaign.voucher_mode || "none",
            voucher_name: campaign.voucher_name || "",
            voucher_type: campaign.voucher_type || "fixed",
            voucher_value: campaign.voucher_value ?? "",
            voucher_usage_limit: campaign.voucher_usage_limit ?? 1,
            voucher_min_spend: campaign.voucher_min_spend ?? "",
            voucher_expires_at: campaign.voucher_expires_at
                ? String(campaign.voucher_expires_at).slice(0, 10)
                : "",
            voucher_prefix: campaign.voucher_prefix || "PROMO",
            shared_voucher_code: campaign.shared_voucher_code || "",
            scheduled_at: campaign.scheduled_at ? String(campaign.scheduled_at).slice(0, 16) : "",
            is_dry_run: !!campaign.is_dry_run,
        });
    };

    const resetCampaign = () => {
        setEditingCampaign(null);
        setCampaignForm(emptyCampaign);
    };

    /*
     * Daftar placeholder harus sama persis dengan yang dibaca server.
     * Kalau berbeda, operator melihat tombol yang muncul untuk placeholder
     * yang ternyata ditolak saat disimpan.
     */
    const KNOWN_PLACEHOLDERS = [
        "{nama}", "{name}", "{tier}", "{poin}", "{points}",
        "{kode_voucher}", "{voucher_code}", "{nama_voucher}", "{voucher_name}",
    ];
    const VOUCHER_PLACEHOLDERS = [
        "{kode_voucher}", "{voucher_code}", "{nama_voucher}", "{voucher_name}",
    ];

    /**
     * Peringatan placeholder, dihitung sebelum campaign disimpan.
     *
     * Ini cermin dari pemeriksaan di server (`assertMessagePlaceholders`),
     * bukan pengganti. Pemeriksaan di server tetap yang memastikan, karena
     * halaman ini bisa dibuka dari perangkat berbeda.
     */
    const placeholderIssues = useMemo(() => {
        const pesan = campaignForm.message || "";
        const issues = [];

        const ditemukan = pesan.match(/\{[a-zA-Z0-9_]+\}/g) || [];
        const takDikenal = [...new Set(ditemukan)].filter(
            (t) => !KNOWN_PLACEHOLDERS.includes(t)
        );

        if (takDikenal.length > 0) {
            issues.push(
                `Placeholder tidak dikenal: ${takDikenal.join(", ")}. `
                    + "Kalau tidak diperbaiki, teks itu akan terkirim apa adanya ke pelanggan."
            );
        }

        if (campaignForm.voucher_mode === "none") {
            const dipakai = VOUCHER_PLACEHOLDERS.filter((t) => pesan.includes(t));
            if (dipakai.length > 0) {
                issues.push(
                    `Pesan memakai ${dipakai.join(", ")} tapi mode voucher `
                        + 'masih "Tanpa Voucher". Pelanggan akan menerima bagian itu kosong.'
                );
            }
        }

        return issues;
    }, [campaignForm.message, campaignForm.voucher_mode]);

    /**
     * Kirim satu pesan uji ke nomor yang dipilih.
     *
     * Tujuannya supaya operator tahu token dan perangkat Fonnte hidup
     * SEBELUM campaign menyentuh pelanggan. Tanpa ini, kesalahan konfigurasi
     * baru muncul setelah pengiriman sungguhan, sebagai `failed` per baris
     * delivery yang jauh lebih sulit ditelusuri.
     */
    const sendTestMessage = () => {
        if (!testForm.phone.trim()) {
            toast.error("Isi nomor tujuan dulu.");
            return;
        }

        setTesting(true);
        router.post(
            route("crm.test-send"),
            { phone: testForm.phone.trim(), channel: testForm.channel },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success("Pesan uji terkirim. Cek di WhatsApp/email tujuan.");
                    setTestForm((prev) => ({ ...prev, phone: "" }));
                },
                onError: (errors) => {
                    toast.error(Object.values(errors)[0] || "Tes koneksi gagal.");
                },
                onFinish: () => setTesting(false),
            }
        );
    };

    const submitCampaign = (event) => {
        event.preventDefault();
        setSavingCampaign(true);
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(editingCampaign ? "Campaign diperbarui." : "Campaign dibuat.");
                resetCampaign();
            },
            onError: (errors) => toast.error(firstErrorMessage(errors, "Campaign gagal disimpan.")),
            onFinish: () => setSavingCampaign(false),
        };
        if (editingCampaign) {
            router.put(route("crm.campaigns.update", editingCampaign), campaignForm, options);
        } else {
            router.post(route("crm.campaigns.store"), campaignForm, options);
        }
    };

    const runCampaign = (campaign) => {
        if (!window.confirm(`Proses campaign "${campaign.name}"?`)) return;
        router.post(route("crm.campaigns.run", campaign.id), { force: true }, {
            preserveScroll: true,
            onSuccess: () => toast.success("Campaign selesai diproses."),
            onError: () => toast.error("Campaign gagal diproses."),
        });
    };

    const remove = (kind, item) => {
        if (!window.confirm(`Hapus ${kind} "${item.name}"?`)) return;
        router.delete(kind === "segment" ? route("crm.segments.destroy", item.id) : route("crm.campaigns.destroy", item.id), {
            preserveScroll: true,
            onSuccess: () => toast.success(`${kind === "segment" ? "Segment" : "Campaign"} dihapus.`),
            onError: () => toast.error("Data gagal dihapus."),
        });
    };

    return (
        <>
            <Head title="CRM Campaigns | LakuPOS" />
            <div className="space-y-6 pb-12">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-black text-slate-900 dark:text-white">
                            <IconBellRinging className="text-pink-500" size={28} /> CRM Campaign Center
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">Buat segment, birthday, tier promotion, dan win-back campaign.</p>
                    </div>
                    <span className="rounded-full bg-pink-100 px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-pink-700 dark:bg-pink-950 dark:text-pink-300">
                        {stats.active_campaigns || 0} campaign berjalan
                    </span>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {[
                        ["Pelanggan aktif", stats.customers || 0, "bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300"],
                        ["Opt-out", stats.opted_out || 0, "bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-300"],
                        ["Campaign aktif", stats.active_campaigns || 0, "bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300"],
                        ["Pesan terkirim", stats.sent_messages || 0, "bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"],
                        ["Pesan gagal", stats.failed_messages || 0, "bg-orange-50 text-orange-700 dark:bg-orange-950 dark:text-orange-300"],
                    ].map(([label, value, className]) => (
                        <div key={label} className={`rounded-2xl p-4 ${className}`}>
                            <p className="text-[10px] font-black uppercase tracking-wider">{label}</p>
                            <p className="mt-2 text-2xl font-black">{value}</p>
                        </div>
                    ))}
                </div>

                <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-xs leading-relaxed text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200">
                    <strong>Preview aman:</strong> campaign baru default-nya dry-run. Admin dapat melihat penerima dan pesan tanpa mengirim WA/email. Aktifkan pengiriman hanya setelah kredensial provider tersedia.
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center justify-between gap-3">
                            <div><h2 className="flex items-center gap-2 text-sm font-black uppercase tracking-wider"><IconUsers size={18} className="text-primary-500" /> Segment Pelanggan</h2><p className="mt-1 text-xs text-slate-500">Filter dinamis dari data transaksi.</p></div>
                            {canManage && <button type="button" onClick={resetSegment} className="text-xs font-black text-primary-600">Reset</button>}
                        </div>
                        {canManage && <form onSubmit={submitSegment} className="mt-5 space-y-3 rounded-xl bg-slate-50 p-4 dark:bg-slate-800">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <input required value={segmentForm.name} onChange={(e) => setSegmentField("name", e.target.value)} placeholder="Nama segment" className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                <input value={segmentForm.description} onChange={(e) => setSegmentField("description", e.target.value)} placeholder="Deskripsi" className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {['bronze', 'silver', 'gold', 'platinum'].map((tier) => <label key={tier} className="flex items-center gap-1 rounded-lg bg-white px-2 py-1 text-[10px] font-black uppercase dark:bg-slate-900"><input type="checkbox" checked={segmentForm.tiers.includes(tier)} onChange={(e) => setSegmentField("tiers", e.target.checked ? [...segmentForm.tiers, tier] : segmentForm.tiers.filter((item) => item !== tier))} /> {tier}</label>)}
                            </div>
                            <div className="grid gap-3 sm:grid-cols-3">
                                <input type="number" min="0" value={segmentForm.min_lifetime_spend} onChange={(e) => setSegmentField("min_lifetime_spend", e.target.value)} placeholder="Min lifetime spend" className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                <input type="number" min="0" value={segmentForm.min_transactions} onChange={(e) => setSegmentField("min_transactions", e.target.value)} placeholder="Min transaksi" className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                <input type="number" min="1" value={segmentForm.inactive_days} onChange={(e) => setSegmentField("inactive_days", e.target.value)} placeholder="Tidak belanja (hari)" className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" />
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <select value={segmentForm.birthday_month} onChange={(e) => setSegmentField("birthday_month", e.target.value)} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900">
                                    <option value="">Bulan lahir: semua</option>
                                    {MONTHS.map((month, index) => <option key={month} value={index + 1}>{month}</option>)}
                                </select>
                                <select value={segmentForm.birthday_day} onChange={(e) => setSegmentField("birthday_day", e.target.value)} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900">
                                    <option value="">Tanggal lahir: semua</option>
                                    {Array.from({ length: 31 }, (_, i) => i + 1).map((day) => <option key={day} value={day}>{day}</option>)}
                                </select>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <select value={segmentForm.product_id} onChange={(e) => setSegmentField("product_id", e.target.value)} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="">Semua produk</option>{products.map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}</select>
                                <select value={segmentForm.category_id} onChange={(e) => setSegmentField("category_id", e.target.value)} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="">Semua kategori</option>{categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
                            </div>
                            <label className="flex items-center gap-2 text-xs font-bold"><input type="checkbox" checked={segmentForm.is_active} onChange={(e) => setSegmentField("is_active", e.target.checked)} /> Segment aktif</label>
                            <button disabled={savingSegment} className="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2.5 text-xs font-black text-white disabled:opacity-50"><IconCheck size={15} /> {editingSegment ? "Update Segment" : "Simpan Segment"}</button>
                        </form>}
                        <div className="mt-5 space-y-2">
                            {segments.length === 0 && <p className="text-sm text-slate-400">Belum ada segment.</p>}
                            {segments.map((segment) => <div key={segment.id} className={`flex items-center justify-between rounded-xl border px-3 py-3 text-xs ${segment.is_active ? "border-slate-100 dark:border-slate-800" : "border-dashed border-amber-300 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-950/20"}`}><div><p className="font-black">{segment.name}</p><p className="mt-1 text-slate-400">{segment.recipients || 0} penerima - {segment.is_active ? "aktif" : "nonaktif (campaign ditunda)"}</p></div>{canManage && <div className="flex gap-1"><button type="button" onClick={() => editSegment(segment)} className="rounded p-1.5 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950"><IconPencil size={15} /></button><button type="button" onClick={() => remove("segment", segment)} className="rounded p-1.5 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950"><IconTrash size={15} /></button></div>}</div>)}
                        </div>
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center justify-between gap-3"><div><h2 className="flex items-center gap-2 text-sm font-black uppercase tracking-wider"><IconBellRinging size={18} className="text-pink-500" /> Campaign</h2><p className="mt-1 text-xs text-slate-500">Birthday, tier, win-back, atau manual.</p></div>{canManage && <button type="button" onClick={resetCampaign} className="text-xs font-black text-primary-600">Reset</button>}</div>
                        {/* TES KONEKSI.

                            Ditaruh tepat di atas form campaign, bukan di halaman
                            Pengaturan, karena yang diuji sebenarnya adalah
                            jalur yang dipakai campaign: token yang sama,
                            perangkat yang sama, serialisasi nomor yang sama.

                            Isinya disengaja menandai ini sebagai pesan uji, supaya
                            penerima tidak salah mengiranya sebagai promo. */}
                        {canManage && (
                            <div className="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                                <p className="flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wider text-slate-500">
                                    <IconDeviceMobile size={13} /> Tes koneksi sebelum kirim massal
                                </p>
                                <p className="mt-1 text-[10px] leading-relaxed text-slate-500">
                                    Kirim satu pesan uji. Pakai nomor Anda sendiri dulu,
                                    jangan nomor pelanggan.
                                </p>
                                <div className="mt-2 grid gap-2 sm:grid-cols-[110px_1fr_auto]">
                                    <select
                                        value={testForm.channel}
                                        onChange={(e) =>
                                            setTestForm((prev) => ({
                                                ...prev,
                                                channel: e.target.value,
                                            }))
                                        }
                                        className="h-10 rounded-lg border border-slate-200 bg-white px-2 text-xs dark:border-slate-700 dark:bg-slate-900"
                                        aria-label="Kanal tes"
                                    >
                                        <option value="whatsapp">WhatsApp</option>
                                        <option value="email">Email</option>
                                    </select>
                                    <input
                                        value={testForm.phone}
                                        onChange={(e) =>
                                            setTestForm((prev) => ({
                                                ...prev,
                                                phone: e.target.value,
                                            }))
                                        }
                                        placeholder="081234567890"
                                        className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"
                                        aria-label="Nomor atau email tujuan tes"
                                    />
                                    <button
                                        type="button"
                                        onClick={sendTestMessage}
                                        disabled={testing}
                                        className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-slate-800 px-4 text-xs font-black text-white disabled:opacity-50 dark:bg-slate-700"
                                    >
                                        {testing ? (
                                            "Mengirim..."
                                        ) : (
                                            <>
                                                <IconPlayerPlay size={14} /> Kirim
                                                Tes
                                            </>
                                        )}
                                    </button>
                                </div>
                            </div>
                        )}
                        {canManage && <form onSubmit={submitCampaign} className="mt-5 space-y-3 rounded-xl bg-slate-50 p-4 dark:bg-slate-800">
                            <input required value={campaignForm.name} onChange={(e) => setCampaignField("name", e.target.value)} placeholder="Nama campaign" className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" />
                            <div className="grid gap-3 sm:grid-cols-2"><select required value={campaignForm.segment_id} onChange={(e) => setCampaignField("segment_id", e.target.value)} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="">Pilih segment</option>{segments.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.recipients || 0})</option>)}</select><select value={campaignForm.type} onChange={(e) => setCampaignField("type", e.target.value)} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="manual">Manual</option><option value="birthday">Birthday</option><option value="tier_promotion">Tier Promotion</option><option value="win_back">Win Back</option></select></div>
                            <div className="grid gap-3 sm:grid-cols-2"><select value={campaignForm.channel} onChange={(e) => setCampaignField("channel", e.target.value)} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="copy">Copy / Preview</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option></select><select value={campaignForm.voucher_mode} onChange={(e) => setCampaignField("voucher_mode", e.target.value)} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="none">Tanpa Voucher</option><option value="per_recipient">Kode Unik per Penerima</option><option value="shared">Satu Kode Promo Bersama</option></select></div>
                            {campaignForm.voucher_mode !== "none" && <div className="space-y-3 rounded-lg border border-pink-200 bg-pink-50/50 p-3 dark:border-pink-900 dark:bg-pink-950/20">
                                <p className="text-[10px] font-black uppercase tracking-wider text-pink-700 dark:text-pink-300">Definisi Voucher</p>
                                <div className="grid gap-3 sm:grid-cols-2"><input value={campaignForm.voucher_name} onChange={(e) => setCampaignField("voucher_name", e.target.value)} placeholder="Nama voucher (mis. Diskon 10rb)" className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /><select value={campaignForm.voucher_type} onChange={(e) => setCampaignField("voucher_type", e.target.value)} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="fixed">Potong Tetap (Rp)</option><option value="percentage">Persentase (%)</option></select></div>
                                <div className="grid gap-3 sm:grid-cols-4"><div><label className="mb-1 block text-[10px] font-bold text-slate-500">Nilai</label><input type="number" min="0" value={campaignForm.voucher_value} onChange={(e) => setCampaignField("voucher_value", e.target.value)} placeholder="10000" className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /></div><div><label className="mb-1 block text-[10px] font-bold text-slate-500">Min Belanja</label><input type="number" min="0" value={campaignForm.voucher_min_spend} onChange={(e) => setCampaignField("voucher_min_spend", e.target.value)} placeholder="0" className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /></div><div><label className="mb-1 block text-[10px] font-bold text-slate-500">Bisa Dipakai</label><input type="number" min="1" max="9999" value={campaignForm.voucher_usage_limit} onChange={(e) => setCampaignField("voucher_usage_limit", e.target.value)} className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /><p className="mt-1 text-[10px] text-slate-500">1 = sekali pakai</p></div><div><label className="mb-1 block text-[10px] font-bold text-slate-500">Berlaku Sampai</label><input type="date" value={campaignForm.voucher_expires_at} onChange={(e) => setCampaignField("voucher_expires_at", e.target.value)} className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /></div></div>
                                {campaignForm.voucher_mode === "per_recipient" ? <div><label className="mb-1 block text-[10px] font-bold text-slate-500">Awalan Kode Unik</label><input value={campaignForm.voucher_prefix} onChange={(e) => setCampaignField("voucher_prefix", e.target.value)} placeholder="PROMO" className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm uppercase dark:border-slate-700 dark:bg-slate-900" /><p className="mt-1 text-[10px] text-slate-500">Setiap penerima mendapat kode berbeda, contoh: <span className="font-black">{campaignForm.voucher_prefix || "PROMO"}-A1B2C3</span></p></div> : <div><label className="mb-1 block text-[10px] font-bold text-slate-500">Kode Promo Bersama</label><input value={campaignForm.shared_voucher_code} onChange={(e) => setCampaignField("shared_voucher_code", e.target.value.toUpperCase())} placeholder="PROMO2026" className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm uppercase dark:border-slate-700 dark:bg-slate-900" /><p className="mt-1 text-[10px] text-slate-500">Semua penerima mendapat kode yang sama. Bisa dipakai pelanggan mana pun di kasir.</p></div>}
                            </div>}
                            {campaignForm.channel === "email" && <input value={campaignForm.subject} onChange={(e) => setCampaignField("subject", e.target.value)} placeholder="Subject email" className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" />}
                            <textarea required value={campaignForm.message} onChange={(e) => setCampaignField("message", e.target.value)} rows={5} placeholder="Tulis pesan. Placeholder yang bisa dipakai: {nama}, {tier}, {poin}, {kode_voucher}, {nama_voucher}" className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
                            {/* Daftar placeholder dibuat bisa diklik, bukan hanya
                                teks di placeholder input. Yang di-absorb dulu cuma
                                contoh satu baris yang cepat hilang begitu kasir
                                mulai mengetik -- dan placeholder yang keliru
                                ketik akan terkensor utuh ke WhatsApp pelanggan
                                tanpa error apa pun. */}
                            <div className="flex flex-wrap items-center gap-1">
                                <span className="text-[10px] font-black uppercase tracking-wider text-slate-400">Sisipkan:</span>
                                {[
                                    { token: "{nama}", label: "Nama", always: true },
                                    { token: "{tier}", label: "Tier", always: true },
                                    { token: "{poin}", label: "Poin", always: true },
                                    { token: "{kode_voucher}", label: "Kode voucher", always: false },
                                    { token: "{nama_voucher}", label: "Nama voucher", always: false },
                                ].map((item) => {
                                    const off = !item.always && campaignForm.voucher_mode === "none";
                                    return (
                                        <button
                                            key={item.token}
                                            type="button"
                                            title={off ? "Mode voucher masih \"Tanpa Voucher\"" : "Sisipkan ke pesan"}
                                            onClick={() => setCampaignField("message", (campaignForm.message || "") + item.token)}
                                            className={`rounded-md border px-2 py-0.5 font-mono text-[10px] font-bold transition-colors ${
                                                off
                                                    ? "cursor-not-allowed border-amber-300 bg-amber-50 text-amber-700 line-through dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-400"
                                                    : "border-slate-200 bg-slate-50 text-slate-600 hover:border-primary-400 hover:text-primary-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                                            }`}
                                        >
                                            {item.token}
                                        </button>
                                    );
                                })}
                            </div>
                            {placeholderIssues.length > 0 && (
                                <ul className="space-y-1 rounded-lg border border-amber-300 bg-amber-50 p-2 text-[10px] font-bold text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                                    {placeholderIssues.map((issue) => (
                                        <li key={issue}>{issue}</li>
                                    ))}
                                </ul>
                            )}
                            <div className="flex flex-wrap items-center gap-3"><label className="flex items-center gap-2 text-xs font-bold"><input type="checkbox" checked={campaignForm.is_dry_run} onChange={(e) => setCampaignField("is_dry_run", e.target.checked)} /> Dry-run / preview</label><input type="datetime-local" value={campaignForm.scheduled_at} onChange={(e) => setCampaignField("scheduled_at", e.target.value)} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-900" /></div>
                            <button disabled={savingCampaign} className="inline-flex items-center gap-2 rounded-lg bg-pink-600 px-4 py-2.5 text-xs font-black text-white disabled:opacity-50"><IconCheck size={15} /> {editingCampaign ? "Update Campaign" : "Simpan Campaign"}</button>
                        </form>}
                        <div className="mt-5 space-y-2">
                            {campaigns.length === 0 && <p className="text-sm text-slate-400">Belum ada campaign.</p>}
                            {campaigns.map((campaign) => <div key={campaign.id} className="rounded-xl border border-slate-100 px-3 py-3 text-xs dark:border-slate-800"><div className="flex items-start justify-between gap-2"><div><Link href={route("crm.campaigns.show", campaign.id)} className="font-black hover:text-primary-600 hover:underline">{campaign.name}</Link><p className="mt-1 text-slate-400">{campaign.segment?.name || "Tanpa segment"} - {campaign.channel} - {campaign.is_dry_run ? "preview" : "live"}</p></div><span className={`rounded-full px-2 py-1 text-[9px] font-black uppercase ${statusClass[campaign.status] || statusClass.draft}`}>{campaign.status}</span></div><div className="mt-2 flex flex-wrap items-center gap-2 text-[10px] text-slate-500"><span>{campaign.total_recipients || 0} penerima</span><span>{campaign.sent_count || 0} terkirim</span><span>{campaign.failed_count || 0} gagal</span>{campaign.scheduled_at && <span>Jadwal: {formatDate(campaign.scheduled_at)}</span>}</div><div className="mt-3 flex gap-2"><Link href={route("crm.campaigns.show", campaign.id)} className="inline-flex items-center gap-1 rounded-lg bg-indigo-50 px-2.5 py-1.5 font-black text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950 dark:text-indigo-300"><IconEye size={14} /> Lihat Pesan</Link>{canManage && <><button type="button" onClick={() => runCampaign(campaign)} className="inline-flex items-center gap-1 rounded-lg bg-primary-50 px-2.5 py-1.5 font-black text-primary-700 hover:bg-primary-100 dark:bg-primary-950 dark:text-primary-300"><IconPlayerPlay size={14} /> Proses</button><button type="button" onClick={() => editCampaign(campaign)} className="rounded-lg p-1.5 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950"><IconPencil size={15} /></button><button type="button" onClick={() => remove("campaign", campaign)} className="rounded-lg p-1.5 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950"><IconTrash size={15} /></button></>}</div></div>)}
                        </div>
                    </section>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 text-xs text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
                    <p className="font-black uppercase tracking-wider text-slate-700 dark:text-slate-200">Opt-out & privasi</p>
                    <p className="mt-1">Pelanggan yang menandai opt-out tidak akan masuk segment atau menerima campaign. Perubahan opt-in/opt-out dapat dilakukan dari profil pelanggan oleh staff.</p>
                </div>
            </div>
        </>
    );
}

Campaigns.layout = (page) => <DashboardLayout children={page} />;
