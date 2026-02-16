import React, { useEffect, useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import InputSelect from "@/Components/Dashboard/InputSelect";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import {
    IconCoin,
    IconDatabaseOff,
    IconPercentage,
    IconReceipt,
    IconTrendingUp,
    IconFilter,
    IconX,
    IconSearch,
    IconWorld,
    IconCash
} from "@tabler/icons-react";

// Summary Card with gradient
const SummaryCard = ({ title, value, description, icon, gradient }) => (
    <div
        className={`relative overflow-hidden rounded-2xl p-5 bg-gradient-to-br ${gradient} text-white shadow-lg`}
    >
        <div className="absolute top-0 right-0 w-24 h-24 opacity-20">
            {React.cloneElement(icon, {
                size: 96,
                strokeWidth: 0.5,
                className: "transform translate-x-4 -translate-y-4",
            })}
        </div>
        <div className="relative z-10">
            <div className="flex items-center gap-2 mb-2">
                <div className="p-2 rounded-xl bg-white/20">
                    {React.cloneElement(icon, { size: 18 })}
                </div>
                <span className="text-sm font-medium opacity-90">{title}</span>
            </div>
            <p className="text-2xl font-bold">{value}</p>
            <p className="text-sm opacity-80 mt-1">{description}</p>
        </div>
    </div>
);

const defaultFilters = {
    start_date: "",
    end_date: "",
    invoice: "",
    cashier_id: "",
    customer_id: "",
};

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const castFilterString = (value) =>
    typeof value === "number" ? String(value) : value ?? "";

const ProfitReport = ({
    transactions,
    summary,
    filters,
    cashiers,
    customers,
}) => {
    const [showFilters, setShowFilters] = useState(false);
    const [filterData, setFilterData] = useState({
        ...defaultFilters,
        ...filters,
    });
    const [selectedCashier, setSelectedCashier] = useState(null);
    const [selectedCustomer, setSelectedCustomer] = useState(null);

    useEffect(() => {
        setFilterData({ ...defaultFilters, ...filters });
        setSelectedCashier(
            cashiers.find((c) => String(c.id) === filters.cashier_id) || null
        );
        setSelectedCustomer(
            customers.find((c) => String(c.id) === filters.customer_id) || null
        );
    }, [filters, cashiers, customers]);

    const handleChange = (field, value) =>
        setFilterData((prev) => ({ ...prev, [field]: value }));

    const applyFilters = (e) => {
        e.preventDefault();
        router.get(route("reports.profits.index"), filterData, {
            preserveState: true,
            preserveScroll: true,
        });
        setShowFilters(false);
    };

    const resetFilters = () => {
        setFilterData(defaultFilters);
        setSelectedCashier(null);
        setSelectedCustomer(null);
        router.get(route("reports.profits.index"), defaultFilters, {
            replace: true,
            preserveScroll: true,
        });
    };

    const rows = transactions?.data ?? [];
    const links = transactions?.links ?? [];
    const currentPage = transactions?.current_page ?? 1;
    const perPage = transactions?.per_page
        ? Number(transactions?.per_page)
        : rows.length || 1;

    const hasActiveFilters =
        filterData.invoice ||
        filterData.start_date ||
        filterData.end_date ||
        filterData.cashier_id ||
        filterData.customer_id;

    // Perhitungan Summary Terintegrasi Akun Beban App
    const stats = {
        profit_total: summary?.profit_total ?? 0,
        revenue_total: summary?.gross_sales ?? 0, // Omzet Bruto
        platform_fees: summary?.app_expenses ?? 0,  // Akun: Beban Komisi App
        net_revenue: summary?.net_revenue ?? 0,     // Uang masuk riil
        total_hpp: summary?.total_hpp ?? 0,         // Akun: HPP
        margin: summary?.margin ?? 0,
        best_invoice: summary?.best_invoice ?? "-",
        best_profit: summary?.best_profit ?? 0,
    };

    const summaryCards = [
        {
            title: "Laba Bersih Final",
            value: formatCurrency(stats.profit_total),
            description: "Bersih potong HPP & App",
            icon: <IconCoin />,
            gradient: "from-emerald-500 to-emerald-700",
        },
        {
            title: "Omzet (Bruto)",
            value: formatCurrency(stats.revenue_total),
            description: "Total bayar pelanggan",
            icon: <IconReceipt />,
            gradient: "from-primary-500 to-primary-700",
        },
        {
            title: "Beban Komisi App",
            value: formatCurrency(stats.platform_fees),
            description: "Markup & Komisi Platform",
            icon: <IconWorld />,
            gradient: "from-orange-500 to-orange-600",
        },
        {
            title: "Margin Bersih",
            value: `${stats.margin}%`,
            description: "Efisiensi bisnis riil",
            icon: <IconPercentage />,
            gradient: "from-indigo-500 to-indigo-700",
        },
    ];

    return (
        <>
            <Head title="Laporan Laba Rugi" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <IconCoin size={28} className="text-success-500" />
                            Laporan Keuntungan & Laba Rugi
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Analisis performa riil setelah beban platform
                        </p>
                    </div>
                    <button
                        onClick={() => setShowFilters(!showFilters)}
                        className={`inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border text-sm font-medium transition-colors ${
                            showFilters || hasActiveFilters
                                ? "bg-primary-50 border-primary-200 text-primary-700 dark:bg-primary-950/50 dark:border-primary-800 dark:text-primary-400"
                                : "bg-white border-slate-200 text-slate-700 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300"
                        }`}
                    >
                        <IconFilter size={18} />
                        <span>Filter</span>
                        {hasActiveFilters && (
                            <span className="w-2 h-2 rounded-full bg-primary-500"></span>
                        )}
                    </button>
                </div>

                {/* Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {summaryCards.map((card) => (
                        <SummaryCard key={card.title} {...card} />
                    ))}
                </div>

                {/* AREA RINCIAN AKUN LABA RUGI */}
                <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-8 shadow-sm">
                    <div className="flex items-center gap-2 mb-8 border-b pb-4 dark:border-slate-800">
                        <IconTrendingUp className="text-primary-500" size={20} />
                        <h2 className="font-black uppercase text-sm tracking-widest text-slate-700 dark:text-white">Rincian Laporan Laba Rugi</h2>
                    </div>

                    <div className="space-y-6">
                        {/* I. PENDAPATAN */}
                        <section>
                            <div className="flex justify-between items-end mb-2">
                                <span className="text-[10px] font-black uppercase text-slate-400 tracking-tighter">I. Pendapatan</span>
                                <span className="h-px flex-1 border-b border-dashed mx-4 mb-1 dark:border-slate-800"></span>
                            </div>
                            <div className="flex justify-between py-2">
                                <span className="text-sm font-bold text-slate-600 dark:text-slate-300 uppercase">Penjualan Bruto (Gross Sales)</span>
                                <span className="font-black text-slate-900 dark:text-white tracking-tighter">{formatCurrency(stats.revenue_total)}</span>
                            </div>
                        </section>

                        {/* II. BEBAN & BIAYA */}
                        <section className="space-y-4">
                            <div className="flex justify-between items-end mb-2">
                                <span className="text-[10px] font-black uppercase text-slate-400 tracking-tighter">II. Beban Operasional & HPP</span>
                                <span className="h-px flex-1 border-b border-dashed mx-4 mb-1 dark:border-slate-800"></span>
                            </div>
                            
                            {/* Akun: HPP */}
                            <div className="flex justify-between py-1 px-4">
                                <span className="text-sm font-medium text-slate-600 dark:text-slate-400">Harga Pokok Penjualan (HPP)</span>
                                <span className="font-bold text-red-500">-{formatCurrency(stats.total_hpp)}</span>
                            </div>

                            {/* AKUN KHUSUS: BEBAN KOMISI APLIKASI */}
                            <div className="flex justify-between py-3 px-5 bg-orange-50/40 dark:bg-orange-950/10 border border-dashed border-orange-200 dark:border-orange-800 rounded-2xl">
                                <div className="flex items-center gap-3">
                                    <IconWorld className="text-orange-500 shrink-0" size={20} />
                                    <div>
                                        <span className="text-sm font-black text-orange-600 dark:text-orange-400 italic block">Beban Komisi Aplikasi Online</span>
                                        <span className="text-[8px] text-orange-400 font-bold uppercase tracking-tighter leading-none">Markup & Fee Layanan Pihak Ketiga</span>
                                    </div>
                                </div>
                                <span className="font-black text-orange-600">-{formatCurrency(stats.platform_fees)}</span>
                            </div>
                        </section>

                        {/* HASIL AKHIR */}
                        <div className="pt-8">
                            <div className="flex justify-between items-center bg-emerald-600 p-6 rounded-[2.5rem] shadow-xl shadow-emerald-200 dark:shadow-none border-4 border-emerald-500">
                                <div className="flex items-center gap-4">
                                    <div className="p-3 bg-white/20 rounded-2xl"><IconCash className="text-white" size={28} /></div>
                                    <div>
                                        <p className="text-[10px] font-black text-emerald-100 uppercase tracking-widest mb-1 text-left">Keuntungan Bersih (Riil)</p>
                                        <h3 className="text-white text-3xl font-black italic tracking-tighter leading-none">NET PROFIT</h3>
                                    </div>
                                </div>
                                <div className="text-right">
                                    <span className="text-white text-3xl font-black leading-none tracking-tighter">
                                        {formatCurrency(stats.profit_total)}
                                    </span>
                                    <div className="text-[10px] font-bold text-emerald-100 mt-1 uppercase italic opacity-80 flex items-center justify-end gap-1">
                                        Sudah Dipotong Modal & Beban Platform
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Filters Panel */}
                {showFilters && (
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 animate-slide-up">
                        <form onSubmit={applyFilters}>
                            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Tanggal Mulai</label>
                                    <input
                                        type="date"
                                        value={filterData.start_date}
                                        onChange={(e) => handleChange("start_date", e.target.value)}
                                        className="w-full h-11 px-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-primary-500/20 transition-all"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Tanggal Akhir</label>
                                    <input
                                        type="date"
                                        value={filterData.end_date}
                                        onChange={(e) => handleChange("end_date", e.target.value)}
                                        className="w-full h-11 px-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-primary-500/20 transition-all"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Invoice</label>
                                    <input
                                        type="text"
                                        placeholder="TRX-..."
                                        value={filterData.invoice}
                                        onChange={(e) => handleChange("invoice", e.target.value)}
                                        className="w-full h-11 px-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-200 placeholder-slate-400 focus:ring-2 focus:ring-primary-500/20 transition-all"
                                    />
                                </div>
                                <InputSelect
                                    label="Kasir"
                                    data={cashiers}
                                    selected={selectedCashier}
                                    setSelected={(v) => {
                                        setSelectedCashier(v);
                                        handleChange("cashier_id", v ? String(v.id) : "");
                                    }}
                                    placeholder="Semua kasir"
                                    searchable
                                />
                                <InputSelect
                                    label="Pelanggan"
                                    data={customers}
                                    selected={selectedCustomer}
                                    setSelected={(v) => {
                                        setSelectedCustomer(v);
                                        handleChange("customer_id", v ? String(v.id) : "");
                                    }}
                                    placeholder="Semua pelanggan"
                                    searchable
                                />
                            </div>
                            <div className="flex justify-end gap-2 mt-4">
                                {hasActiveFilters && (
                                    <button
                                        type="button"
                                        onClick={resetFilters}
                                        className="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-100 transition-colors"
                                    >
                                        <IconX size={18} />
                                    </button>
                                )}
                                <button
                                    type="submit"
                                    className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors"
                                >
                                    <IconSearch size={18} />
                                    Terapkan
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                {/* Table List Transaksi */}
                {rows.length > 0 ? (
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead>
                                    <tr className="border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50">
                                        <th className="px-4 py-4 text-left text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase">No</th>
                                        <th className="px-4 py-4 text-left text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase">Invoice</th>
                                        <th className="px-4 py-4 text-left text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase border-l dark:border-slate-800">Platform</th>
                                        <th className="px-4 py-4 text-right text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase">Omzet Bruto</th>
                                        <th className="px-4 py-4 text-right text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase bg-emerald-50/30 dark:bg-emerald-950/10 border-l dark:border-slate-800">Laba Bersih</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {rows.map((trx, i) => (
                                        <tr
                                            key={trx.id}
                                            className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                                        >
                                            <td className="px-4 py-4 text-sm text-slate-600">
                                                {i + 1 + (currentPage - 1) * perPage}
                                            </td>
                                            <td className="px-4 py-4">
                                                <div className="text-sm font-bold text-slate-900 dark:text-white">{trx.invoice}</div>
                                                <div className="text-[10px] text-slate-400 uppercase">{trx.created_at}</div>
                                            </td>
                                            <td className="px-4 py-4 border-l dark:border-slate-800">
                                                {trx.online_platform ? (
                                                    <span className="flex items-center gap-1 text-[9px] font-black text-emerald-600 bg-emerald-50 dark:bg-emerald-900/20 px-2 py-0.5 rounded-lg uppercase w-fit border border-emerald-100 dark:border-emerald-900">
                                                        <IconWorld size={12} /> {trx.online_platform}
                                                    </span>
                                                ) : (
                                                    <span className="text-[9px] font-bold text-slate-300 uppercase italic">Offline</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-4 text-right text-sm text-slate-900 dark:text-white font-medium">{formatCurrency(trx.grand_total ?? 0)}</td>
                                            <td className="px-4 py-4 text-right text-sm font-black text-emerald-600 dark:text-emerald-400 bg-emerald-50/20 dark:bg-emerald-950/5 border-l dark:border-slate-800">
                                                {formatCurrency(trx.total_profit ?? 0)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col items-center justify-center py-16 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800">
                        <div className="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                            <IconDatabaseOff
                                size={32}
                                className="text-slate-400"
                            />
                        </div>
                        <h3 className="text-lg font-medium text-slate-800 dark:text-slate-200 mb-1">
                            Tidak Ada Data
                        </h3>
                        <p className="text-sm text-slate-500">
                            Tidak ada transaksi sesuai filter.
                        </p>
                    </div>
                )}

                {links.length > 3 && <Pagination links={links} />}
            </div>
        </>
    );
};

ProfitReport.layout = (page) => <DashboardLayout children={page} />;

export default ProfitReport;