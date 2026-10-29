import React, { useEffect, useState } from "react";
import { Head, router, Link, usePage } from "@inertiajs/react";
import Swal from "sweetalert2";
import PaymentTimeline from "@/Components/Dashboard/PaymentTimeline";
import { useTheme } from "@/Context/ThemeSwitcherContext";
import {
    isPaid,
    paymentBadgeLabel,
    paymentLabel,
} from "@/Utils/PaymentLabels";
import {
    IconSearch,
    IconHistory,
    IconPrinter,
    IconRefresh,
    IconArmchair,
    IconUser,
    IconFilter,
    IconX,
    IconChevronLeft,
    IconChevronRight,
    IconCash,
    IconQrcode,
    IconDeviceMobileVibration,
    IconCreditCard,
    IconCircleX,
    IconArrowLeft,
    IconChevronDown,
    IconAlertCircle,
    IconSun,
    IconMoon,
} from "@tabler/icons-react";
import { formatDate, formatDateTime, formatTime } from "@/Utils/DateTime";

/**
 * Riwayat Transaksi versi kasir.
 *
 * Bedanya dari Dashboard/Transactions/History.jsx hanya tampilannya. Data,
 * filter, aturan akses, dan riwayat pembayarannya diambil dari endpoint yang
 * sama, jadi kedua halaman selalu menampilkan catatan yang sama.
 *
 * Prinsip tampilannya mengikuti halaman kasir, bukan dashboard:
 *
 *  - Penuh tinggi layar, tanpa sidebar. Di kasir tidak ada ruang untuk
 *    kerangka navigasi yang tidak dipakai.
 *  - Baris padat. Kasir mencari struk milik meja tertentu, bukan membandingkan
 *    laporan, jadi yang dibutuhkan adalah banyak baris sekaligus yang masih
 *    bisa dibaca.
 *  - Ketuk baris untuk membuka detail, bukan tombol "lihat" terpisah.
 *    Di layar sentuh, target tapnya harus besar.
 */
const defaultFilters = {
    invoice: "",
    start_date: "",
    end_date: "",
    payment_method: "",
};

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

// Format waktu lewat DateTime.js supaya zona waktunya Asia/Jakarta,
// bukan zona waktu browser. Lihat catatan di berkas itu.
const formatWaktu = (value) => formatDateTime(value);

/** Warna badge metode bayar, mengikuti PaymentLabels yang dipakai struk. */
function MethodBadge({ method }) {
    const label = paymentBadgeLabel(method);

    const tones = {
        cash: "bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400",
        qris_manual: "bg-cyan-100 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-400",
        midtrans: "bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-400",
        xendit: "bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-400",
        transfer: "bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400",
    };

    const icons = {
        cash: IconCash,
        qris_manual: IconQrcode,
        midtrans: IconDeviceMobileVibration,
        xendit: IconDeviceMobileVibration,
        transfer: IconCreditCard,
    };

    const Icon = icons[method] ?? IconCreditCard;

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-md px-2 py-1 text-[10px] font-black uppercase whitespace-nowrap ${
                tones[method] ?? "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
            }`}
        >
            <Icon size={12} />
            {label}
        </span>
    );
}

export default function HistoryCashier({ transactions, filters }) {
    const { auth } = usePage().props;

    // Tema diambil dari ThemeSwitcherContext, provider yang sama dengan seluruh
    // halaman lain. Jadi tema di sini ikut dengan tema aplikasi, bukan punya
    // state sendiri yang bisa berbeda.
    //
    // Tombol tema juga ditaruh di header karena halaman ini full-screen tanpa
    // sidebar: tanpa tombol, kasir tidak punya cara berganti tema dari sini.
    // themeSwitcher menulis ke localStorage, jadi pilihan ini ikut terbawa ke
    // halaman berikutnya.
    const { darkMode, themeSwitcher } = useTheme();

    const [filterData, setFilterData] = useState({ ...defaultFilters, ...filters });
    const [showFilters, setShowFilters] = useState(false);
    const [openId, setOpenId] = useState(null);

    useEffect(() => {
        setFilterData({ ...defaultFilters, ...filters });
    }, [filters]);

    // Kembali ke kasir. Kalau kasir sedang memegang pesanan, Inertia
    // navigateTo lebih aman daripada location: state keranjang tetap
    // terpasang dan tidak perlu ambil ulang daftar produk.
    const kembaliKeKasir = () => {
        if (window.history.state?.back) {
            router.visit(window.history.state.back);
        } else {
            router.get(route("transactions.index"));
        }
    };

    const handleChange = (field, value) =>
        setFilterData((prev) => ({ ...prev, [field]: value }));

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(route("transactions.history.cashier"), filterData, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const resetFilters = () => {
        setFilterData(defaultFilters);
        router.get(route("transactions.history.cashier"), defaultFilters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const goToPage = (url) => url && router.get(url, {}, { preserveScroll: true, preserveState: true });

    const handleRefund = (id) => {
        Swal.fire({
            title: "Otorisasi Refund",
            text: "Konfirmasi refund akan mengembalikan stok barang. Masukkan password Super Admin:",
            input: "password",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "Verifikasi & Refund",
            confirmButtonColor: "#4f46e5",
            cancelButtonColor: "#64748b",
            showLoaderOnConfirm: true,
            preConfirm: (password) => {
                if (!password) Swal.showValidationMessage("Password wajib diisi!");
                return password;
            },
        }).then((result) => {
            if (!result.isConfirmed) return;

            router.post(
                route("transactions.refund", id),
                { password: result.value },
                {
                    onSuccess: () => Swal.fire("Berhasil!", "Transaksi telah direfund.", "success"),
                    onError: (errors) =>
                        Swal.fire("Gagal!", errors.password || "Terjadi kesalahan sistem.", "error"),
                }
            );
        });
    };

    const rows = transactions?.data ?? [];
    const links = transactions?.links ?? [];
    const currentPage = transactions?.current_page ?? 1;
    const lastPage = transactions?.last_page ?? 1;
    const total = transactions?.total ?? 0;

    const isSuperAdmin = auth.user.roles.some((role) => role.name === "super-admin");
    const hasActiveFilters = filterData.invoice || filterData.start_date || filterData.end_date || filterData.payment_method;

    const prevUrl = links.find((l) => l.label === "&laquo; Previous" || l.label === "‹")?.url;
    const nextUrl = links.find((l) => l.label === "Next &raquo;" || l.label === "›")?.url;

    return (
        <>
            <Head title="Riwayat Transaksi | LakuPOS AJA" />

            <div
                className={`flex h-[100dvh] w-full flex-col overflow-hidden ${
                    darkMode ? "bg-slate-950 text-slate-200" : "bg-slate-100 text-slate-800"
                }`}
            >
                {/* ---------- HEADER ---------- */}
                <header
                    className={`flex h-14 shrink-0 items-center gap-2 border-b px-3 ${
                        darkMode ? "border-slate-800 bg-slate-900" : "border-slate-200 bg-white"
                    }`}
                >
                    <button
                        type="button"
                        onClick={kembaliKeKasir}
                        title="Kembali ke Kasir"
                        className={`flex items-center gap-2 rounded-xl px-3 py-2 text-[10px] font-black uppercase transition-all active:scale-95 ${
                            darkMode
                                ? "bg-slate-800 text-slate-300 hover:bg-slate-700"
                                : "bg-slate-100 text-slate-600 dark:text-slate-400 hover:bg-slate-200"
                        }`}
                    >
                        <IconArrowLeft size={18} />
                        <span className="hidden sm:inline">Kasir</span>
                    </button>

                    <div className="flex min-w-0 items-center gap-2">
                        <IconHistory size={20} className="shrink-0 text-primary-500 dark:text-primary-400" />
                        <h1 className="truncate text-sm font-black uppercase tracking-tighter">
                            Riwayat Transaksi
                        </h1>
                        <span
                            className={`hidden rounded-md px-2 py-0.5 text-[10px] font-black sm:inline ${
                                darkMode ? "bg-slate-800 text-slate-400" : "bg-slate-100 text-slate-500 dark:text-slate-400"
                            }`}
                        >
                            {new Intl.NumberFormat("id-ID").format(total)} transaksi
                        </span>
                    </div>

                    <div className="ml-auto flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setShowFilters((v) => !v)}
                            className={`flex items-center gap-1.5 rounded-xl px-3 py-2 text-[10px] font-black uppercase transition-all ${
                                showFilters || hasActiveFilters
                                    ? "bg-primary-500 dark:bg-primary-600 text-white"
                                    : darkMode
                                      ? "bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-slate-700"
                                      : "bg-slate-100 text-slate-600 dark:text-slate-400 hover:bg-slate-200"
                            }`}
                        >
                            <IconFilter size={16} />
                            <span className="hidden sm:inline">Filter</span>
                        </button>

                        <button
                            type="button"
                            onClick={themeSwitcher}
                            title={darkMode ? "Mode Terang" : "Mode Gelap"}
                            className={`rounded-xl p-2 transition-all active:scale-95 ${
                                darkMode
                                    ? "bg-slate-800 text-yellow-400 hover:bg-slate-700"
                                    : "bg-slate-100 text-slate-600 dark:text-slate-400 hover:bg-slate-200"
                            }`}
                        >
                            {darkMode ? <IconSun size={18} /> : <IconMoon size={18} />}
                        </button>

                        <button
                            type="button"
                            onClick={() =>
                                router.reload({ only: ["transactions", "filters"], preserveScroll: true })
                            }
                            title="Muat ulang"
                            className={`rounded-xl p-2 transition-all active:scale-95 ${
                                darkMode
                                    ? "bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-slate-700"
                                    : "bg-slate-100 text-slate-600 dark:text-slate-400 hover:bg-slate-200"
                            }`}
                        >
                            <IconRefresh size={18} />
                        </button>
                    </div>
                </header>

                {/* ---------- FILTER ---------- */}
                {showFilters && (
                    <form
                        onSubmit={applyFilters}
                        className={`shrink-0 border-b px-3 py-3 ${
                            darkMode ? "border-slate-800 bg-slate-900" : "border-slate-200 bg-white"
                        }`}
                    >
                        <div className="flex flex-wrap items-end gap-2">
                            <label className="min-w-[160px] flex-1 text-[10px] font-black uppercase tracking-wider opacity-60">
                                No. Invoice
                                <div className="relative mt-1">
                                    <IconSearch
                                        size={14}
                                        className="absolute left-2.5 top-1/2 -translate-y-1/2 opacity-40"
                                    />
                                    <input
                                        type="text"
                                        value={filterData.invoice}
                                        onChange={(e) => handleChange("invoice", e.target.value)}
                                        placeholder="TRX-..."
                                        className={`w-full rounded-lg border py-2 pl-8 pr-2 text-xs focus:ring-1 focus:ring-primary-500 ${
                                            darkMode
                                                ? "border-slate-700 bg-slate-800 text-white"
                                                : "border-slate-200 bg-white text-slate-800"
                                        }`}
                                    />
                                </div>
                            </label>

                            <label className="text-[10px] font-black uppercase tracking-wider opacity-60">
                                Metode
                                <select
                                    value={filterData.payment_method}
                                    onChange={(e) => handleChange("payment_method", e.target.value)}
                                    className={`mt-1 block rounded-lg border px-2 py-2 text-xs focus:ring-1 focus:ring-primary-500 ${
                                        darkMode
                                            ? "border-slate-700 bg-slate-800 text-white"
                                            : "border-slate-200 bg-white text-slate-800"
                                    }`}
                                >
                                    <option value="">Semua</option>
                                    <option value="cash">Tunai</option>
                                    <option value="qris_manual">QRIS Statis</option>
                                    <option value="midtrans">QRIS Midtrans</option>
                                    <option value="xendit">QRIS Xendit</option>
                                    <option value="transfer">Transfer</option>
                                </select>
                            </label>

                            <label className="text-[10px] font-black uppercase tracking-wider opacity-60">
                                Dari
                                <input
                                    type="date"
                                    value={filterData.start_date}
                                    onChange={(e) => handleChange("start_date", e.target.value)}
                                    className={`mt-1 block rounded-lg border px-2 py-2 text-xs focus:ring-1 focus:ring-primary-500 ${
                                        darkMode
                                            ? "border-slate-700 bg-slate-800 text-white"
                                            : "border-slate-200 bg-white text-slate-800"
                                    }`}
                                />
                            </label>

                            <label className="text-[10px] font-black uppercase tracking-wider opacity-60">
                                Sampai
                                <input
                                    type="date"
                                    value={filterData.end_date}
                                    onChange={(e) => handleChange("end_date", e.target.value)}
                                    className={`mt-1 block rounded-lg border px-2 py-2 text-xs focus:ring-1 focus:ring-primary-500 ${
                                        darkMode
                                            ? "border-slate-700 bg-slate-800 text-white"
                                            : "border-slate-200 bg-white text-slate-800"
                                    }`}
                                />
                            </label>

                            <div className="flex items-center gap-2">
                                <button
                                    type="submit"
                                    className="rounded-lg bg-primary-600 px-4 py-2 text-[10px] font-black uppercase tracking-widest text-white hover:bg-primary-500"
                                >
                                    Terapkan
                                </button>
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    title="Reset filter"
                                    className={`rounded-lg p-2 transition-colors ${
                                        darkMode
                                            ? "bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-slate-700"
                                            : "bg-slate-100 text-slate-500 dark:text-slate-400 hover:bg-slate-200"
                                    }`}
                                >
                                    <IconX size={16} />
                                </button>
                            </div>
                        </div>
                    </form>
                )}

                {/* ---------- DAFTAR TRANSAKSI ---------- */}
                <div className="min-h-0 flex-1 overflow-y-auto">
                    {rows.length > 0 ? (
                        <ul className="divide-y">
                            {rows.map((transaction) => {
                                const refunded = transaction.status === "refunded";
                                const isOpen = openId === transaction.id;

                                return (
                                    <li
                                        key={transaction.id}
                                        className={refunded ? "opacity-60" : ""}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => setOpenId(isOpen ? null : transaction.id)}
                                            aria-expanded={isOpen}
                                            className={`flex w-full items-center gap-2 px-3 py-2.5 text-left transition-colors ${
                                                /*
                                                    Latar ditulis EKSPLISIT di kedua
                                                    tema, bukan mengandalkan transparan.

                                                    Plugin @tailwindcss/forms memberi
                                                    `button` latar putih secara
                                                    bawaan, dan aturan gelapnya tidak
                                                    selalu menang. Kalau baris
                                                    tinggal tanpa kelas latar, di
                                                    mode gelap baris tetap putih
                                                    dengan teks gelap di atas
                                                    latar gelap halaman.
                                                */
                                                darkMode
                                                    ? isOpen
                                                        ? "bg-slate-800 hover:bg-slate-800"
                                                        : "bg-slate-900 hover:bg-slate-800"
                                                    : isOpen
                                                      ? "bg-slate-200 hover:bg-slate-200"
                                                      : "bg-white hover:bg-slate-50"
                                            }`}
                                        >
                                            {/* Waktu */}
                                            <div className="w-[86px] shrink-0">
                                                <div className="text-[11px] font-black">
                                                    {formatTime(transaction.created_at, {
                                                        hour: "2-digit",
                                                        minute: "2-digit",
                                                    })}
                                                </div>
                                                <div className="text-[10px] opacity-50">
                                                    {formatDate(transaction.created_at, {
                                                        day: "2-digit",
                                                        month: "short",
                                                    })}
                                                </div>
                                            </div>

                                            {/* Invoice + meja/pelanggan */}
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span
                                                        className={`truncate text-xs font-black uppercase ${
                                                            refunded ? "text-red-500 line-through" : ""
                                                        }`}
                                                    >
                                                        {transaction.invoice}
                                                    </span>
                                                    {refunded && (
                                                        <span className="rounded bg-red-500 px-1.5 py-0.5 text-[9px] font-black text-white">
                                                            REFUND
                                                        </span>
                                                    )}
                                                    {!isPaid(transaction) && (
                                                        <span className="flex items-center gap-1 rounded bg-amber-500 px-1.5 py-0.5 text-[9px] font-black text-white">
                                                            <IconAlertCircle size={10} />
                                                            BELUM LUNAS
                                                        </span>
                                                    )}
                                                </div>

                                                <div className="mt-0.5 flex items-center gap-3 text-[10px] opacity-60">
                                                    <span className="flex items-center gap-1">
                                                        <IconArmchair size={10} />
                                                        {transaction.table_name || "Bawa Pulang"}
                                                    </span>
                                                    <span className="flex items-center gap-1 truncate">
                                                        <IconUser size={10} />
                                                        {transaction.customer_name ||
                                                            transaction.customer?.name ||
                                                            "Umum"}
                                                    </span>
                                                    <span className="hidden sm:inline truncate">
                                                        {transaction.cashier?.name}
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Metode */}
                                            <div className="hidden sm:block shrink-0">
                                                <MethodBadge method={transaction.payment_method} />
                                            </div>

                                            {/* Total */}
                                            <div className="w-[104px] shrink-0 text-right">
                                                <div className="text-xs font-black">
                                                    {formatCurrency(transaction.grand_total)}
                                                </div>
                                                {Number(transaction.total_profit || 0) > 0 && (
                                                    <div className="text-[10px] font-bold text-emerald-500">
                                                        +{formatCurrency(transaction.total_profit)}
                                                    </div>
                                                )}
                                            </div>

                                            <IconChevronDown
                                                size={16}
                                                className={`shrink-0 opacity-40 transition-transform ${
                                                    isOpen ? "rotate-180" : ""
                                                }`}
                                            />
                                        </button>

                                        {/* Detail yang terbuka */}
                                        {isOpen && (
                                            <div
                                                className={`border-t px-3 py-3 ${
                                                    darkMode
                                                        ? "border-slate-800 bg-slate-900/60"
                                                        : "border-slate-200 bg-slate-50/70"
                                                }`}
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="text-[10px] font-black uppercase opacity-50">
                                                        {formatWaktu(transaction.created_at)}
                                                    </span>

                                                    <Link
                                                        href={route("transactions.print", transaction.invoice)}
                                                        className="flex items-center gap-1.5 rounded-lg bg-slate-200 px-2.5 py-1.5 text-[10px] font-black uppercase transition-colors hover:bg-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                                    >
                                                        <IconPrinter size={14} />
                                                        Cetak Ulang
                                                    </Link>

                                                    {transaction.receipt_url && (
                                                        <a
                                                            href={transaction.receipt_url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="flex items-center gap-1.5 rounded-lg bg-emerald-100 px-2.5 py-1.5 text-[10px] font-black uppercase text-emerald-700 transition-colors hover:bg-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-400"
                                                        >
                                                            <IconQrcode size={14} />
                                                            Struk Digital
                                                        </a>
                                                    )}

                                                    {isSuperAdmin && !refunded && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRefund(transaction.id)}
                                                            className="flex items-center gap-1.5 rounded-lg bg-red-100 px-2.5 py-1.5 text-[10px] font-black uppercase text-red-600 transition-colors hover:bg-red-200 dark:bg-red-500/15 dark:text-red-400"
                                                        >
                                                            <IconRefresh size={14} />
                                                            Refund
                                                        </button>
                                                    )}

                                                    {refunded && (
                                                        <span className="flex items-center gap-1.5 rounded-lg bg-red-100 px-2.5 py-1.5 text-[10px] font-black uppercase text-red-600 dark:bg-red-500/15 dark:text-red-400">
                                                            <IconCircleX size={14} />
                                                            Sudah Direfund
                                                        </span>
                                                    )}

                                                    {Number(transaction.total_items || 0) > 0 && (
                                                        <span className="text-[10px] font-bold opacity-50">
                                                            {transaction.total_items} item
                                                        </span>
                                                    )}
                                                </div>

                                                {(transaction.payment_timeline?.length > 0) && (
                                                    <div className="mt-3">
                                                        <PaymentTimeline transaction={transaction} />
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    ) : (
                        <div className="flex h-full flex-col items-center justify-center gap-2 p-8 text-center">
                            <IconHistory size={40} className="opacity-20" />
                            <p className="text-sm font-medium opacity-50">Tidak ada transaksi ditemukan.</p>
                            {hasActiveFilters && (
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    className="mt-2 rounded-lg bg-slate-200 px-3 py-1.5 text-[10px] font-black uppercase dark:bg-slate-800"
                                >
                                    Reset Filter
                                </button>
                            )}
                        </div>
                    )}
                </div>

                {/* ---------- KAKI: PAGINASI ---------- */}
                {/*
                    Cuma navigasi halaman. Ringkasan omzet/laba halaman ini
                    DIHAPUS atas permintaan: angkanya hanya 10 baris pertama
                    dari 1.014 transaksi, jadi lebih sering disalahartikan
                    sebagai omzet hari ini daripada membantu. Yang butuh
                    angka periode ada di Analitik Grafis.
                */}
                {rows.length > 0 && (
                    <footer
                        className={`flex shrink-0 flex-wrap items-center gap-3 border-t px-3 py-2 text-[10px] ${
                            darkMode
                                ? "border-slate-800 bg-slate-900 text-slate-500 dark:text-slate-400"
                                : "border-slate-200 bg-white text-slate-500 dark:text-slate-400"
                        }`}
                    >
                        <span className="font-bold">
                            Halaman {currentPage} / {lastPage}
                        </span>

                        <div className="ml-auto flex items-center gap-1">
                            <button
                                type="button"
                                onClick={() => goToPage(prevUrl)}
                                disabled={!prevUrl}
                                className="rounded-lg p-1.5 transition-colors disabled:opacity-30 enabled:hover:bg-slate-100 dark:enabled:hover:bg-slate-800"
                                title="Halaman sebelumnya"
                            >
                                <IconChevronLeft size={18} />
                            </button>
                            <button
                                type="button"
                                onClick={() => goToPage(nextUrl)}
                                disabled={!nextUrl}
                                className="rounded-lg p-1.5 transition-colors disabled:opacity-30 enabled:hover:bg-slate-100 dark:enabled:hover:bg-slate-800"
                                title="Halaman berikutnya"
                            >
                                <IconChevronRight size={18} />
                            </button>
                        </div>
                    </footer>
                )}
            </div>
        </>
    );
}
