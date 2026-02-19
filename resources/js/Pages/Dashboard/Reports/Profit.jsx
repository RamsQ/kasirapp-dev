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
    IconArrowDownRight
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

    // Perhitungan Summary Terintegrasi Akun Beban App & Operasional
    const stats = {
        profit_total: summary?.profit_total ?? 0,        // NET PROFIT AKHIR
        gross_margin: summary?.gross_margin ?? 0,        // Laba Produk
        revenue_total: summary?.gross_sales ?? 0,       // Omzet Bruto
        platform_fees: summary?.app_expenses ?? 0,       // Beban App
        operating_expense: summary?.operating_expense ?? 0, // BEBAN KASIR (BARU)
        net_revenue: summary?.net_revenue ?? 0,
        total_hpp: summary?.total_hpp ?? 0,
        margin_perc: summary?.margin_percentage ?? 0,
    };

    const summaryCards = [
        {
            title: "Laba Bersih Final",
            value: formatCurrency(stats.profit_total),
            description: "Setelah potong beban operasional",
            icon: <IconCoin />,
            gradient: "from-emerald-600 to-emerald-800",
        },
        {
            title: "Beban Operasional",
            value: formatCurrency(stats.operating_expense),
            description: "Total kas keluar/biaya",
            icon: <IconCashOff />,
            gradient: "from-red-500 to-red-700",
        },
        {
            title: "Beban Komisi App",
            value: formatCurrency(stats.platform_fees),
            description: "Markup & Fee Platform",
            icon: <IconWorld />,
            gradient: "from-orange-500 to-orange-600",
        },
        {
            title: "Margin Bersih",
            value: `${stats.margin_perc}%`,
            description: "Profitabilitas riil bisnis",
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
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2 uppercase tracking-tighter italic">
                            <IconCoin size={28} className="text-emerald-500" />
                            Profit & Loss Statement
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 font-medium">
                            Analisis performa riil setelah beban platform & operasional
                        </p>
                    </div>
                    <button
                        onClick={() => setShowFilters(!showFilters)}
                        className={`inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border text-sm font-bold transition-all active:scale-95 ${
                            showFilters || hasActiveFilters
                                ? "bg-primary-50 border-primary-200 text-primary-700 dark:bg-primary-950/50 dark:border-primary-800 dark:text-primary-400 shadow-lg shadow-primary-500/10"
                                : "bg-white border-slate-200 text-slate-700 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300"
                        }`}
                    >
                        <IconFilter size={18} />
                        <span>Filter Laporan</span>
                        {hasActiveFilters && (
                            <span className="w-2 h-2 rounded-full bg-primary-500 animate-pulse"></span>
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
                <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-8 shadow-sm">
                    <div className="flex items-center justify-between mb-8 border-b pb-4 dark:border-slate-800">
                        <div className="flex items-center gap-2">
                            <IconTrendingUp className="text-primary-500" size={20} />
                            <h2 className="font-black uppercase text-xs tracking-widest text-slate-700 dark:text-white">Account breakdown</h2>
                        </div>
                        <div className="text-[10px] font-black bg-slate-100 dark:bg-slate-800 px-3 py-1 rounded-full uppercase text-slate-500">
                            IDR Currency
                        </div>
                    </div>

                    <div className="max-w-4xl mx-auto space-y-8">
                        {/* I. PENDAPATAN */}
                        <section>
                            <div className="flex items-center gap-2 mb-4">
                                <span className="text-[10px] font-black uppercase text-primary-500 bg-primary-50 dark:bg-primary-900/20 px-2 py-0.5 rounded">I. Revenue</span>
                                <div className="h-px flex-1 bg-slate-100 dark:bg-slate-800"></div>
                            </div>
                            <div className="flex justify-between py-2 border-b border-slate-50 dark:border-slate-800/50">
                                <span className="text-sm font-bold text-slate-600 dark:text-slate-300">Total Penjualan Bruto</span>
                                <span className="font-black text-slate-900 dark:text-white tracking-tighter">{formatCurrency(stats.revenue_total)}</span>
                            </div>
                        </section>

                        {/* II. BEBAN LANGSUNG */}
                        <section>
                            <div className="flex items-center gap-2 mb-4">
                                <span className="text-[10px] font-black uppercase text-orange-500 bg-orange-50 dark:bg-orange-900/20 px-2 py-0.5 rounded">II. Direct Costs & COGS</span>
                                <div className="h-px flex-1 bg-slate-100 dark:bg-slate-800"></div>
                            </div>
                            <div className="space-y-2">
                                <div className="flex justify-between py-2">
                                    <span className="text-sm font-medium text-slate-500">Harga Pokok Penjualan (HPP)</span>
                                    <span className="font-bold text-slate-700 dark:text-slate-300">-{formatCurrency(stats.total_hpp)}</span>
                                </div>
                                <div className="flex justify-between py-2 text-orange-600 dark:text-orange-400 bg-orange-50/30 dark:bg-orange-950/10 px-4 rounded-xl border border-dashed border-orange-200 dark:border-orange-800">
                                    <div className="flex items-center gap-2 uppercase text-[10px] font-black italic">
                                        <IconWorld size={14}/> Komisi & Fee Aplikasi Online
                                    </div>
                                    <span className="font-black">-{formatCurrency(stats.platform_fees)}</span>
                                </div>
                            </div>
                        </section>

                        {/* III. BEBAN OPERASIONAL KASIR */}
                        <section>
                            <div className="flex items-center gap-2 mb-4">
                                <span className="text-[10px] font-black uppercase text-red-500 bg-red-50 dark:bg-red-900/20 px-2 py-0.5 rounded">III. Operating Expenses</span>
                                <div className="h-px flex-1 bg-slate-100 dark:bg-slate-800"></div>
                            </div>
                            <div className="flex justify-between py-3 px-4 bg-red-50/30 dark:bg-red-950/10 border border-red-100 dark:border-red-900/30 rounded-2xl">
                                <div className="flex items-center gap-2">
                                    <IconCashOff size={18} className="text-red-500" />
                                    <div>
                                        <p className="text-sm font-black text-red-600 dark:text-red-400 uppercase tracking-tighter">Beban Kas Keluar (Operasional)</p>
                                        <p className="text-[8px] font-bold text-red-400 uppercase">Input dari menu Kas Keluar Kasir</p>
                                    </div>
                                </div>
                                <span className="font-black text-red-600">-{formatCurrency(stats.operating_expense)}</span>
                            </div>
                        </section>

                        {/* FINAL NET PROFIT */}
                        <div className="pt-6">
                            <div className="bg-emerald-600 p-8 rounded-[3rem] text-white shadow-2xl shadow-emerald-500/20 flex flex-col md:flex-row justify-between items-center gap-6 relative overflow-hidden">
                                <div className="absolute top-0 right-0 p-4 opacity-10 rotate-12">
                                    <IconCash size={120} />
                                </div>
                                <div className="relative z-10 text-center md:text-left">
                                    <p className="text-[10px] font-black uppercase tracking-[0.3em] opacity-80 mb-2">Bottom Line Result</p>
                                    <h3 className="text-4xl font-black italic tracking-tighter leading-none">REAL NET PROFIT</h3>
                                </div>
                                <div className="relative z-10 text-center md:text-right">
                                    <div className="text-4xl font-black tracking-tighter">{formatCurrency(stats.profit_total)}</div>
                                    <div className="flex items-center justify-center md:justify-end gap-1 text-[10px] font-bold bg-white/20 px-3 py-1 rounded-full mt-2 uppercase">
                                        <IconArrowDownRight size={14}/> Profit setelah semua biaya
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Filters Panel */}
                {showFilters && (
                    <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-6 shadow-xl animate-in slide-in-from-top-4 duration-300">
                        <form onSubmit={applyFilters}>
                            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                                <div>
                                    <label className="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1">Tanggal Mulai</label>
                                    <input
                                        type="date"
                                        value={filterData.start_date}
                                        onChange={(e) => handleChange("start_date", e.target.value)}
                                        className="w-full h-11 px-4 rounded-xl border-none bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-white focus:ring-2 focus:ring-primary-500 transition-all font-bold"
                                    />
                                </div>
                                <div>
                                    <label className="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1">Tanggal Akhir</label>
                                    <input
                                        type="date"
                                        value={filterData.end_date}
                                        onChange={(e) => handleChange("end_date", e.target.value)}
                                        className="w-full h-11 px-4 rounded-xl border-none bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-white focus:ring-2 focus:ring-primary-500 transition-all font-bold"
                                    />
                                </div>
                                <div>
                                    <label className="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1">No. Invoice</label>
                                    <input
                                        type="text"
                                        placeholder="TRX-..."
                                        value={filterData.invoice}
                                        onChange={(e) => handleChange("invoice", e.target.value)}
                                        className="w-full h-11 px-4 rounded-xl border-none bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-white placeholder-slate-400 focus:ring-2 focus:ring-primary-500 transition-all font-bold uppercase"
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
                                />
                            </div>
                            <div className="flex justify-end gap-2 mt-6">
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    className="px-6 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 font-bold hover:bg-slate-100 transition-all"
                                >
                                    RESET
                                </button>
                                <button
                                    type="submit"
                                    className="inline-flex items-center gap-2 px-8 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-black uppercase text-xs tracking-widest transition-all shadow-lg shadow-primary-500/20"
                                >
                                    <IconSearch size={16} />
                                    CARI DATA
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                {/* Table List Transaksi */}
                <Table.Card title="Itemized Profit per Transaction">
                    {rows.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead>
                                    <tr className="border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50">
                                        <th className="px-4 py-4 text-left text-[10px] font-black text-slate-500 uppercase tracking-tighter">No</th>
                                        <th className="px-4 py-4 text-left text-[10px] font-black text-slate-500 uppercase tracking-tighter">Invoice Detail</th>
                                        <th className="px-4 py-4 text-left text-[10px] font-black text-slate-500 uppercase tracking-tighter border-l dark:border-slate-800">Platform Context</th>
                                        <th className="px-4 py-4 text-right text-[10px] font-black text-slate-500 uppercase tracking-tighter">Gross Sales</th>
                                        <th className="px-4 py-4 text-right text-[10px] font-black text-slate-500 uppercase tracking-tighter bg-emerald-50/30 dark:bg-emerald-950/10 border-l dark:border-slate-800">Laba Bersih</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {rows.map((trx, i) => (
                                        <tr
                                            key={trx.id}
                                            className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group"
                                        >
                                            <td className="px-4 py-4 text-xs font-bold text-slate-400 group-hover:text-primary-500 transition-colors">
                                                {i + 1 + (currentPage - 1) * perPage}
                                            </td>
                                            <td className="px-4 py-4">
                                                <div className="text-sm font-black text-slate-900 dark:text-white">{trx.invoice}</div>
                                                <div className="text-[9px] text-slate-400 font-bold uppercase tracking-tighter">{trx.created_at}</div>
                                            </td>
                                            <td className="px-4 py-4 border-l dark:border-slate-800">
                                                {trx.online_platform ? (
                                                    <span className="flex items-center gap-1 text-[9px] font-black text-emerald-600 bg-emerald-50 dark:bg-emerald-900/20 px-2 py-0.5 rounded-lg uppercase w-fit border border-emerald-100 dark:border-emerald-900">
                                                        <IconWorld size={12} /> {trx.online_platform}
                                                    </span>
                                                ) : (
                                                    <span className="text-[9px] font-bold text-slate-300 uppercase italic">Standard / Offline</span>
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
                    ) : (
                        <div className="flex flex-col items-center justify-center py-20">
                            <div className="w-20 h-20 rounded-full bg-slate-50 dark:bg-slate-800 flex items-center justify-center mb-4">
                                <IconDatabaseOff size={40} className="text-slate-300" />
                            </div>
                            <h3 className="text-lg font-black text-slate-800 dark:text-white uppercase italic tracking-tighter">No Financial Records</h3>
                            <p className="text-xs text-slate-500 font-medium">Coba ubah filter tanggal atau pencarian Anda.</p>
                        </div>
                    )}
                </Table.Card>

                {links.length > 3 && <Pagination links={links} />}
            </div>
        </>
    );
};

ProfitReport.layout = (page) => <DashboardLayout children={page} />;

export default ProfitReport;