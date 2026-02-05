import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link } from "@inertiajs/react";
import { useEffect, useMemo, useRef } from "react";
import Chart from "chart.js/auto";
import {
    IconBox,
    IconCategory,
    IconMoneybag,
    IconUsers,
    IconCoin,
    IconReceipt,
    IconTrendingUp,
    IconArrowUpRight,
    IconArrowDownRight,
    IconShoppingCart,
    IconChartBar,
    IconClock,
    IconAlertTriangle, // Icon Bahaya
    IconCalendarTime   // Icon Waktu
} from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

// Stat Card Component
function StatCard({ title, value, subtitle, icon: Icon, gradient, trend }) {
    return (
        <div
            className={`
            relative overflow-hidden rounded-2xl p-5
            bg-gradient-to-br ${gradient}
            text-white shadow-lg
        `}
        >
            {/* Background Pattern */}
            <div className="absolute top-0 right-0 w-32 h-32 opacity-20">
                <Icon
                    size={128}
                    strokeWidth={0.5}
                    className="transform translate-x-8 -translate-y-8"
                />
            </div>

            <div className="relative z-10">
                <div className="flex items-center gap-2 mb-3">
                    <div className="p-2 rounded-xl bg-white/20">
                        <Icon size={20} strokeWidth={1.5} />
                    </div>
                    <span className="text-sm font-medium opacity-90">
                        {title}
                    </span>
                </div>

                <p className="text-3xl font-bold">{value}</p>

                {subtitle && (
                    <p className="mt-2 text-sm opacity-80 flex items-center gap-1">
                        {trend === "up" && <IconArrowUpRight size={14} />}
                        {trend === "down" && <IconArrowDownRight size={14} />}
                        {subtitle}
                    </p>
                )}
            </div>
        </div>
    );
}

// Info Card Component
function InfoCard({ title, value, subtitle, icon: Icon }) {
    return (
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 transition-all hover:shadow-md hover:border-slate-300 dark:hover:border-slate-700">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        {title}
                    </p>
                    <p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                        {value}
                    </p>
                    {subtitle && (
                        <p className="mt-1 text-xs text-slate-400 dark:text-slate-500 flex items-center gap-1">
                            <Icon size={14} />
                            {subtitle}
                        </p>
                    )}
                </div>
                <div className="p-3 rounded-xl bg-slate-100 dark:bg-slate-800">
                    <Icon
                        size={24}
                        className="text-slate-600 dark:text-slate-400"
                        strokeWidth={1.5}
                    />
                </div>
            </div>
        </div>
    );
}

// List Card Component
function ListCard({ title, subtitle, icon: Icon, children, emptyMessage }) {
    return (
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden">
            <div className="p-5 border-b border-slate-100 dark:border-slate-800">
                <div className="flex items-center gap-2">
                    <div className="p-2 rounded-lg bg-primary-100 dark:bg-primary-900/30">
                        <Icon
                            size={18}
                            className="text-primary-600 dark:text-primary-400"
                        />
                    </div>
                    <div>
                        <h3 className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                            {title}
                        </h3>
                        {subtitle && (
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                {subtitle}
                            </p>
                        )}
                    </div>
                </div>
            </div>
            <div className="p-5">
                {children || (
                    <div className="flex h-32 items-center justify-center text-sm text-slate-400 dark:text-slate-500">
                        {emptyMessage}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function Dashboard({
    totalCategories,
    totalProducts,
    totalTransactions,
    totalUsers,
    revenueTrend,
    totalRevenue,
    totalProfit,
    averageOrder,
    todayTransactions,
    topProducts = [],
    recentTransactions = [],
    topCustomers = [],
    expiredProducts = [], // Props baru
    expiringProducts = [] // Props baru
}) {
    const chartRef = useRef(null);
    const chartInstance = useRef(null);

    const chartData = useMemo(() => revenueTrend ?? [], [revenueTrend]);

    // Setup chart
    useEffect(() => {
        if (!chartRef.current) return;

        if (chartInstance.current) {
            chartInstance.current.destroy();
            chartInstance.current = null;
        }

        if (!chartData.length) return;

        const labels = chartData.map((item) => item.label);
        const totals = chartData.map((item) => item.total);

        const ctx = chartRef.current.getContext("2d");
        const gradient = ctx.createLinearGradient(0, 0, 0, 200);
        gradient.addColorStop(0, "rgba(99, 102, 241, 0.3)");
        gradient.addColorStop(1, "rgba(99, 102, 241, 0.01)");

        chartInstance.current = new Chart(chartRef.current, {
            type: "line",
            data: {
                labels,
                datasets: [
                    {
                        label: "Pendapatan",
                        data: totals,
                        borderColor: "#6366f1",
                        backgroundColor: gradient,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: "#6366f1",
                        pointHoverBorderColor: "#fff",
                        pointHoverBorderWidth: 2,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: "index",
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: "#1e293b",
                        titleColor: "#f1f5f9",
                        bodyColor: "#f1f5f9",
                        padding: 12,
                        borderRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: (ctx) => formatCurrency(ctx.raw),
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => formatCurrency(value),
                            color: "#94a3b8",
                            font: { size: 11 },
                        },
                        grid: {
                            color: "rgba(148, 163, 184, 0.1)",
                            drawBorder: false,
                        },
                        border: { display: false },
                    },
                    x: {
                        ticks: {
                            color: "#94a3b8",
                            font: { size: 11 },
                        },
                        grid: { display: false },
                        border: { display: false },
                    },
                },
            },
        });

        return () => chartInstance.current?.destroy();
    }, [chartData]);

    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                            Dashboard
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Ringkasan aktivitas bisnis Anda
                        </p>
                    </div>
                    <Link
                        href={route("transactions.index")}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors shadow-lg shadow-primary-500/30"
                    >
                        <IconShoppingCart size={18} />
                        <span>Transaksi Baru</span>
                    </Link>
                </div>

                {/* Main Stat Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <StatCard
                        title="Total Pendapatan"
                        value={formatCurrency(totalRevenue)}
                        subtitle="Akumulasi semua transaksi"
                        icon={IconCoin}
                        gradient="from-primary-500 to-primary-700"
                    />
                    <StatCard
                        title="Total Profit"
                        value={formatCurrency(totalProfit)}
                        subtitle="Profit bersih"
                        icon={IconTrendingUp}
                        gradient="from-success-500 to-success-700"
                        trend="up"
                    />
                    <StatCard
                        title="Rata-Rata Order"
                        value={formatCurrency(averageOrder)}
                        subtitle="Per transaksi"
                        icon={IconReceipt}
                        gradient="from-accent-500 to-accent-700"
                    />
                    <StatCard
                        title="Transaksi Hari Ini"
                        value={todayTransactions}
                        subtitle="Transaksi"
                        icon={IconClock}
                        gradient="from-warning-500 to-warning-600"
                    />
                </div>

                {/* Secondary Stats */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <InfoCard
                        title="Total Kategori"
                        value={totalCategories}
                        icon={IconCategory}
                    />
                    <InfoCard
                        title="Total Produk"
                        value={totalProducts}
                        icon={IconBox}
                    />
                    <InfoCard
                        title="Total Transaksi"
                        value={totalTransactions}
                        icon={IconMoneybag}
                    />
                    <InfoCard
                        title="Total Pengguna"
                        value={totalUsers}
                        icon={IconUsers}
                    />
                </div>

                {/* --- SEKSI NOTIFIKASI EXPIRED (Hanya Muncul Jika Ada Data) --- */}
                {(expiredProducts.length > 0 || expiringProducts.length > 0) && (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        
                        {/* A. SUDAH EXPIRED (MERAH) */}
                        {expiredProducts.length > 0 && (
                            <div className="bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800 rounded-2xl p-6">
                                <div className="flex items-center gap-3 mb-4">
                                    <div className="p-2 bg-red-100 dark:bg-red-900/30 rounded-full text-red-600 dark:text-red-400">
                                        <IconAlertTriangle size={24} />
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-bold text-red-800 dark:text-red-400">Produk Sudah Kadaluarsa!</h3>
                                        <p className="text-sm text-red-600 dark:text-red-300">Segera tarik produk ini dari rak.</p>
                                    </div>
                                </div>
                                <div className="space-y-3 max-h-60 overflow-y-auto pr-2 custom-scrollbar">
                                    {expiredProducts.map((product, index) => (
                                        <div key={index} className="flex justify-between items-center bg-white dark:bg-slate-800 p-3 rounded-xl border border-red-100 dark:border-red-900/30 shadow-sm">
                                            <span className="font-medium text-slate-700 dark:text-slate-200 truncate">{product.title}</span>
                                            <span className="text-xs font-bold text-red-600 dark:text-red-400 px-2 py-1 bg-red-100 dark:bg-red-900/30 rounded-lg whitespace-nowrap">
                                                {product.expired_date}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* B. AKAN EXPIRED (KUNING) */}
                        {expiringProducts.length > 0 && (
                            <div className="bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800 rounded-2xl p-6">
                                <div className="flex items-center gap-3 mb-4">
                                    <div className="p-2 bg-amber-100 dark:bg-amber-900/30 rounded-full text-amber-600 dark:text-amber-400">
                                        <IconCalendarTime size={24} />
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-bold text-amber-800 dark:text-amber-400">Segera Expired (30 Hari)</h3>
                                        <p className="text-sm text-amber-600 dark:text-amber-300">Pertimbangkan diskon cuci gudang.</p>
                                    </div>
                                </div>
                                <div className="space-y-3 max-h-60 overflow-y-auto pr-2 custom-scrollbar">
                                    {expiringProducts.map((product, index) => (
                                        <div key={index} className="flex justify-between items-center bg-white dark:bg-slate-800 p-3 rounded-xl border border-amber-100 dark:border-amber-900/30 shadow-sm">
                                            <span className="font-medium text-slate-700 dark:text-slate-200 truncate">{product.title}</span>
                                            <span className="text-xs font-bold text-amber-700 dark:text-amber-400 px-2 py-1 bg-amber-100 dark:bg-amber-900/30 rounded-lg whitespace-nowrap">
                                                {product.expired_date}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* Charts and Lists Row */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Revenue Chart */}
                    <ListCard
                        title="Tren Pendapatan"
                        subtitle="12 data terakhir"
                        icon={IconChartBar}
                        emptyMessage="Belum ada data pendapatan"
                    >
                        {chartData.length > 0 && (
                            <div className="h-64">
                                <canvas ref={chartRef} />
                            </div>
                        )}
                    </ListCard>

                    {/* Top Products */}
                    <ListCard
                        title="Produk Terlaris"
                        subtitle="Berdasarkan penjualan"
                        icon={IconBox}
                        emptyMessage="Belum ada data produk"
                    >
                        {topProducts.length > 0 && (
                            <ul className="space-y-3">
                                {topProducts.map((product, index) => (
                                    <li
                                        key={index}
                                        className="flex items-center justify-between p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                                    >
                                        <div className="flex items-center gap-3">
                                            <span className="w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-900/50 text-primary-600 dark:text-primary-400 text-xs font-bold flex items-center justify-center">
                                                {index + 1}
                                            </span>
                                            <div>
                                                <p className="text-sm font-medium text-slate-800 dark:text-slate-200">
                                                    {product.name}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {product.qty} terjual
                                                </p>
                                            </div>
                                        </div>
                                        <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                            {formatCurrency(product.total)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </ListCard>
                </div>

                {/* Bottom Row */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Recent Transactions */}
                    <ListCard
                        title="Transaksi Terbaru"
                        subtitle="5 transaksi terakhir"
                        icon={IconReceipt}
                        emptyMessage="Belum ada transaksi"
                    >
                        {recentTransactions.length > 0 && (
                            <div className="space-y-3">
                                {recentTransactions.map((trx, index) => (
                                    <div
                                        key={index}
                                        className="flex items-center justify-between p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50"
                                    >
                                        <div>
                                            <p className="text-sm font-semibold text-slate-800 dark:text-slate-200">
                                                {trx.invoice}
                                            </p>
                                            <p className="text-xs text-slate-500 mt-0.5">
                                                {trx.date} • {trx.customer}
                                            </p>
                                            <p className="text-xs text-slate-400">
                                                Kasir: {trx.cashier}
                                            </p>
                                        </div>
                                        <p className="text-sm font-bold text-primary-600 dark:text-primary-400">
                                            {formatCurrency(trx.total)}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </ListCard>

                    {/* Top Customers */}
                    <ListCard
                        title="Pelanggan Terbaik"
                        subtitle="Berdasarkan nilai pembelian"
                        icon={IconUsers}
                        emptyMessage="Belum ada data pelanggan"
                    >
                        {topCustomers.length > 0 && (
                            <ul className="space-y-3">
                                {topCustomers.map((customer, index) => (
                                    <li
                                        key={index}
                                        className="flex items-center justify-between p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                                    >
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-full bg-gradient-to-br from-accent-400 to-accent-600 flex items-center justify-center text-white text-sm font-bold">
                                                {customer.name.charAt(0)}
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium text-slate-800 dark:text-slate-200">
                                                    {customer.name}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {customer.orders} transaksi
                                                </p>
                                            </div>
                                        </div>
                                        <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                            {formatCurrency(customer.total)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </ListCard>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = (page) => <DashboardLayout children={page} />;