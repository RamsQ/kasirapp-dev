import React, { useEffect, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
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
    IconCash,
    IconCashOff,
    IconArrowDownRight,
    IconAlertCircle
} from "@tabler/icons-react";

// Summary Card with modern gradient & glassmorphism effect
const SummaryCard = ({ title, value, description, icon, gradient }) => (
    <div
        className={`relative overflow-hidden rounded-[2rem] p-6 bg-gradient-to-br ${gradient} text-white shadow-xl transition-transform hover:scale-[1.02] duration-300`}
    >
        <div className="absolute top-0 right-0 w-32 h-32 opacity-10">
            {React.cloneElement(icon, {
                size: 120,
                strokeWidth: 1,
                className: "transform translate-x-8 -translate-y-8",
            })}
        </div>
        <div className="relative z-10">
            <div className="flex items-center gap-3 mb-4">
                <div className="p-2.5 rounded-2xl bg-white/20 backdrop-blur-md border border-white/10">
                    {React.cloneElement(icon, { size: 22 })}
                </div>
                <span className="text-xs font-black uppercase tracking-widest opacity-90">{title}</span>
            </div>
            <p className="text-3xl font-black tracking-tighter mb-1">{value}</p>
            <p className="text-[10px] font-bold uppercase tracking-tight opacity-70 italic">{description}</p>
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

    const stats = {
        profit_total: summary?.profit_total ?? 0,
        gross_margin: summary?.gross_margin ?? 0,
        revenue_total: summary?.gross_sales ?? 0,
        platform_fees: summary?.app_expenses ?? 0,
        operating_expense: summary?.operating_expense ?? 0,
        net_revenue: summary?.net_revenue ?? 0,
        total_hpp: summary?.total_hpp ?? 0,
        margin_perc: summary?.margin_percentage ?? 0,
    };

    const summaryCards = [
        {
            title: "Laba Bersih Final",
            value: formatCurrency(stats.profit_total),
            description: "Setelah potong biaya operasional",
            icon: <IconCoin />,
            gradient: "from-emerald-500 to-emerald-700",
        },
        {
            title: "Beban Kas Keluar",
            value: formatCurrency(stats.operating_expense),
            description: "Biaya operasional/operasional",
            icon: <IconCashOff />,
            gradient: "from-rose-500 to-rose-700",
        },
        {
            title: "Beban Komisi App",
            value: formatCurrency(stats.platform_fees),
            description: "Markup & Biaya Online",
            icon: <IconWorld />,
            gradient: "from-amber-500 to-amber-600",
        },
        {
            title: "Profitabilitas",
            value: `${stats.margin_perc}%`,
            description: "Margin laba bersih riil",
            icon: <IconPercentage />,
            gradient: "from-indigo-600 to-indigo-800",
        },
    ];

    return (
        <>
            <Head title="Laporan Laba Rugi | POS SYSTEM AJA" />

            <div className="space-y-6">
                {/* Header Section */}
                <div className="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white flex items-center gap-3 uppercase tracking-tighter italic">
                            <div className="p-2 bg-indigo-600 rounded-xl text-white shadow-lg shadow-indigo-500/20">
                                <IconCoin size={24} />
                            </div>
                            Profit & Loss Statement
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 font-medium mt-1">
                            Laporan laba bersih setelah dikurangi HPP, Komisi Aplikasi, dan Beban Operasional.
                        </p>
                    </div>
                    <button
                        onClick={() => setShowFilters(!showFilters)}
                        className={`inline-flex items-center gap-2 px-5 py-3 rounded-2xl border text-xs font-black uppercase tracking-widest transition-all active:scale-95 shadow-sm ${
                            showFilters || hasActiveFilters
                                ? "bg-indigo-50 border-indigo-200 text-indigo-700 dark:bg-indigo-950/50 dark:border-indigo-800 dark:text-indigo-400"
                                : "bg-white border-slate-200 text-slate-700 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-300"
                        }`}
                    >
                        <IconFilter size={18} />
                        <span>Filter Analisis</span>
                        {hasActiveFilters && (
                            <span className="w-2 h-2 rounded-full bg-indigo-600 animate-ping"></span>
                        )}
                    </button>
                </div>

                {/* Summary Statistics Cards */}
                <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
                    {summaryCards.map((card) => (
                        <SummaryCard key={card.title} {...card} />
                    ))}
                </div>

                {/* Account Breakdown Section (Main Financial View) */}
                <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-8 lg:p-12 shadow-sm relative overflow-hidden">
                    <div className="absolute top-0 right-0 p-8 opacity-[0.03] dark:opacity-[0.05] pointer-events-none">
                        <IconTrendingUp size={240} />
                    </div>
                    
                    <div className="flex items-center justify-between mb-10 border-b border-slate-100 dark:border-slate-800 pb-6">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600">
                                <IconTrendingUp size={20} />
                            </div>
                            <h2 className="font-black uppercase text-sm tracking-[0.2em] text-slate-800 dark:text-white">Financial Breakdown</h2>
                        </div>
                        <div className="hidden sm:flex text-[10px] font-black bg-slate-100 dark:bg-slate-800 px-4 py-1.5 rounded-full uppercase text-slate-500 tracking-widest border border-slate-200/50 dark:border-slate-700">
                            Currency: IDR
                        </div>
                    </div>

                    <div className="max-w-4xl mx-auto space-y-10">
                        {/* I. PENDAPATAN */}
                        <section className="animate-in slide-in-from-bottom-2 duration-500">
                            <div className="flex items-center gap-4 mb-6">
                                <span className="text-[11px] font-black uppercase text-indigo-600 bg-indigo-50 dark:bg-indigo-950/40 px-3 py-1 rounded-lg border border-indigo-100 dark:border-indigo-900">I. Revenue</span>
                                <div className="h-px flex-1 bg-slate-100 dark:bg-slate-800"></div>
                            </div>
                            <div className="flex justify-between items-center py-4 border-b border-slate-50 dark:border-slate-800/50 hover:bg-slate-50/50 dark:hover:bg-slate-800/20 px-2 transition-colors rounded-xl">
                                <span className="text-sm font-bold text-slate-600 dark:text-slate-300">Total Penjualan Bruto (Sales)</span>
                                <span className="text-xl font-black text-slate-900 dark:text-white tracking-tighter">{formatCurrency(stats.revenue_total)}</span>
                            </div>
                        </section>

                        {/* II. BIAYA LANGSUNG & HPP */}
                        <section className="animate-in slide-in-from-bottom-4 duration-700">
                            <div className="flex items-center gap-4 mb-6">
                                <span className="text-[11px] font-black uppercase text-amber-600 bg-amber-50 dark:bg-amber-950/40 px-3 py-1 rounded-lg border border-amber-100 dark:border-amber-900">II. Cost of Goods Sold</span>
                                <div className="h-px flex-1 bg-slate-100 dark:bg-slate-800"></div>
                            </div>
                            <div className="space-y-3">
                                <div className="flex justify-between items-center py-2 px-2">
                                    <span className="text-sm font-bold text-slate-500">Harga Pokok Penjualan (Total HPP)</span>
                                    <span className="font-black text-slate-700 dark:text-slate-300 text-lg">-{formatCurrency(stats.total_hpp)}</span>
                                </div>
                                <div className="flex justify-between items-center py-4 px-5 text-amber-700 dark:text-amber-400 bg-amber-50/30 dark:bg-amber-950/10 rounded-2xl border border-dashed border-amber-200 dark:border-amber-900">
                                    <div className="flex items-center gap-3 uppercase text-[10px] font-black italic">
                                        <IconWorld size={18} className="opacity-70"/> Komisi & Fee Aplikasi Online (Platform)
                                    </div>
                                    <span className="font-black text-lg">-{formatCurrency(stats.platform_fees)}</span>
                                </div>
                            </div>
                        </section>

                        {/* III. BEBAN OPERASIONAL */}
                        <section className="animate-in slide-in-from-bottom-6 duration-1000">
                            <div className="flex items-center gap-4 mb-6">
                                <span className="text-[11px] font-black uppercase text-rose-600 bg-rose-50 dark:bg-rose-950/40 px-3 py-1 rounded-lg border border-rose-100 dark:border-rose-900">III. Operating Expenses</span>
                                <div className="h-px flex-1 bg-slate-100 dark:bg-slate-800"></div>
                            </div>
                            <div className="flex justify-between items-center py-5 px-6 bg-rose-50/30 dark:bg-rose-950/10 border border-rose-100 dark:border-rose-900/40 rounded-3xl group transition-all hover:bg-rose-50/50">
                                <div className="flex items-center gap-4">
                                    <div className="w-10 h-10 rounded-2xl bg-rose-500 flex items-center justify-center text-white shadow-lg shadow-rose-500/20 group-hover:rotate-6 transition-transform">
                                        <IconCashOff size={20} />
                                    </div>
                                    <div>
                                        <p className="text-sm font-black text-rose-600 dark:text-rose-400 uppercase tracking-tighter">Beban Kas Keluar</p>
                                        <p className="text-[9px] font-bold text-rose-400 uppercase opacity-70">Pengeluaran Operasional Toko / Kasir</p>
                                    </div>
                                </div>
                                <span className="font-black text-xl text-rose-600">-{formatCurrency(stats.operating_expense)}</span>
                            </div>
                        </section>

                        {/* FINAL NET PROFIT HERO BOX */}
                        <div className="pt-8">
                            <div className="bg-gradient-to-br from-indigo-600 to-indigo-900 p-8 lg:p-12 rounded-[3.5rem] text-white shadow-2xl shadow-indigo-500/30 flex flex-col md:flex-row justify-between items-center gap-8 relative overflow-hidden">
                                <div className="absolute top-0 right-0 p-4 opacity-5 rotate-12 pointer-events-none">
                                    <IconCash size={200} />
                                </div>
                                <div className="relative z-10 text-center md:text-left">
                                    <div className="inline-flex items-center gap-2 px-3 py-1 bg-white/10 rounded-full border border-white/20 mb-4 animate-pulse">
                                        <div className="w-1.5 h-1.5 rounded-full bg-emerald-400"></div>
                                        <span className="text-[9px] font-black uppercase tracking-[0.2em]">Net Profit Result</span>
                                    </div>
                                    <h3 className="text-5xl font-black italic tracking-tighter leading-none uppercase">REAL NET PROFIT</h3>
                                    <p className="text-indigo-100 text-xs font-bold mt-4 uppercase tracking-widest opacity-80 italic">Profit bersih final setelah akumulasi seluruh beban</p>
                                </div>
                                <div className="relative z-10 text-center md:text-right">
                                    <div className="text-5xl font-black tracking-tighter drop-shadow-lg">{formatCurrency(stats.profit_total)}</div>
                                    <div className="flex items-center justify-center md:justify-end gap-2 text-[10px] font-black bg-emerald-500/20 px-5 py-2 rounded-full mt-5 border border-emerald-400/30 text-emerald-300 uppercase tracking-widest">
                                        <IconArrowDownRight size={16} className="animate-bounce" /> Profitabilitas Optimal
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Filters Panel Panel (Slide down) */}
                {showFilters && (
                    <div className="bg-white dark:bg-slate-900 rounded-[2rem] border border-slate-200 dark:border-slate-800 p-8 shadow-2xl animate-in slide-in-from-top-4 duration-300">
                        <form onSubmit={applyFilters} className="space-y-8">
                            <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-5">
                                <div className="space-y-2">
                                    <label className="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest text-left">Tanggal Mulai</label>
                                    <input
                                        type="date"
                                        value={filterData.start_date}
                                        onChange={(e) => handleChange("start_date", e.target.value)}
                                        className="w-full h-12 px-5 rounded-2xl border-none bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 transition-all font-bold"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <label className="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">Tanggal Akhir</label>
                                    <input
                                        type="date"
                                        value={filterData.end_date}
                                        onChange={(e) => handleChange("end_date", e.target.value)}
                                        className="w-full h-12 px-5 rounded-2xl border-none bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 transition-all font-bold"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <label className="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">No. Invoice</label>
                                    <input
                                        type="text"
                                        placeholder="Cari TRX-..."
                                        value={filterData.invoice}
                                        onChange={(e) => handleChange("invoice", e.target.value)}
                                        className="w-full h-12 px-5 rounded-2xl border-none bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-white placeholder-slate-400 focus:ring-2 focus:ring-indigo-500 transition-all font-bold uppercase"
                                    />
                                </div>
                                <InputSelect
                                    label="Pilih Kasir"
                                    data={cashiers}
                                    selected={selectedCashier}
                                    setSelected={(v) => {
                                        setSelectedCashier(v);
                                        handleChange("cashier_id", v ? String(v.id) : "");
                                    }}
                                    placeholder="Semua kasir"
                                />
                                <InputSelect
                                    label="Pilih Pelanggan"
                                    data={customers}
                                    selected={selectedCustomer}
                                    setSelected={(v) => {
                                        setSelectedCustomer(v);
                                        handleChange("customer_id", v ? String(v.id) : "");
                                    }}
                                    placeholder="Semua pelanggan"
                                />
                            </div>
                            <div className="flex justify-end gap-3 pt-4 border-t dark:border-slate-800">
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    className="px-8 py-3 rounded-2xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 font-black text-[10px] uppercase tracking-widest hover:bg-slate-50 transition-all active:scale-95"
                                >
                                    Reset
                                </button>
                                <button
                                    type="submit"
                                    className="inline-flex items-center gap-2 px-10 py-3 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-black uppercase text-[10px] tracking-widest transition-all shadow-lg shadow-indigo-500/30 active:scale-95"
                                >
                                    <IconSearch size={16} />
                                    Terapkan Filter
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                {/* Table: Itemized Profit per Transaction */}
                <div className="bg-white dark:bg-slate-900 rounded-[2rem] border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                    <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                         <h3 className="font-black uppercase text-[10px] tracking-[0.2em] text-slate-500">Transactionized Profit Listing</h3>
                         <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                    </div>
                    {rows.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead>
                                    <tr className="bg-slate-50/50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">
                                        <th className="px-6 py-5 text-left text-[10px] font-black text-slate-500 uppercase tracking-widest">No</th>
                                        <th className="px-6 py-5 text-left text-[10px] font-black text-slate-500 uppercase tracking-widest">Invoice / Date</th>
                                        <th className="px-6 py-5 text-left text-[10px] font-black text-slate-500 uppercase tracking-widest border-l dark:border-slate-800">Platform Context</th>
                                        <th className="px-6 py-5 text-right text-[10px] font-black text-slate-500 uppercase tracking-widest">Gross Sales</th>
                                        <th className="px-6 py-5 text-right text-[10px] font-black text-indigo-600 uppercase tracking-widest bg-indigo-50/20 dark:bg-indigo-950/10 border-l dark:border-slate-800">Item Profit</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50 dark:divide-slate-800">
                                    {rows.map((trx, i) => (
                                        <tr
                                            key={trx.id}
                                            className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group"
                                        >
                                            <td className="px-6 py-5 text-xs font-bold text-slate-400 group-hover:text-indigo-600 transition-colors">
                                                {i + 1 + (currentPage - 1) * perPage}
                                            </td>
                                            <td className="px-6 py-5">
                                                <div className="text-sm font-black text-slate-900 dark:text-white uppercase tracking-tighter">{trx.invoice}</div>
                                                <div className="text-[9px] text-slate-400 font-bold uppercase tracking-tighter mt-0.5">{trx.created_at}</div>
                                            </td>
                                            <td className="px-6 py-5 border-l dark:border-slate-800">
                                                {trx.online_platform ? (
                                                    <span className="flex items-center gap-1.5 text-[10px] font-black text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 px-3 py-1 rounded-xl uppercase w-fit border border-emerald-100 dark:border-emerald-900/50">
                                                        <IconWorld size={14} /> {trx.online_platform}
                                                    </span>
                                                ) : (
                                                    <span className="text-[10px] font-black text-slate-300 dark:text-slate-700 uppercase italic tracking-widest">Offline POS</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-5 text-right text-sm text-slate-900 dark:text-white font-black">{formatCurrency(trx.grand_total ?? 0)}</td>
                                            <td className="px-6 py-5 text-right text-sm font-black text-emerald-600 dark:text-emerald-400 bg-emerald-50/20 dark:bg-emerald-950/5 border-l dark:border-slate-800 drop-shadow-sm">
                                                {formatCurrency(trx.total_profit ?? 0)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="flex flex-col items-center justify-center py-24 grayscale opacity-40">
                            <IconDatabaseOff size={64} className="text-slate-300 mb-4" />
                            <h3 className="text-lg font-black text-slate-800 dark:text-white uppercase italic tracking-tighter">No Financial Data Found</h3>
                            <p className="text-[10px] text-slate-500 font-bold uppercase tracking-widest mt-2">Ubah parameter filter untuk melihat hasil analisis</p>
                        </div>
                    )}
                </div>

                <div className="flex justify-center mt-8 pb-10">
                    {links.length > 3 && <Pagination links={links} />}
                </div>
            </div>
        </>
    );
};

ProfitReport.layout = (page) => <DashboardLayout children={page} />;

export default ProfitReport;