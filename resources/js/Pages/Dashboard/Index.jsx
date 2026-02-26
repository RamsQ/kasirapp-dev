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
    IconAlertTriangle,
    IconCalendarTime,
    IconDeviceMobileVibration,
    IconCreditCard,
    IconBuildingBank,
    IconCash,
    IconQrcode,
    IconChartPie
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
        <div className={`relative overflow-hidden rounded-2xl p-5 bg-gradient-to-br ${gradient} text-white shadow-lg`}>
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
                <p className="text-3xl font-bold tracking-tighter">{value}</p>
                {subtitle && (
                    <p className="mt-2 text-sm opacity-80 flex items-center gap-1 leading-none">
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
function InfoCard({ title, value, subtitle, icon: Icon, colorClass = "text-slate-600 dark:text-slate-400" }) {
    return (
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 transition-all hover:shadow-md hover:border-slate-300 dark:hover:border-slate-700">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-sm text-slate-500 dark:text-slate-400 font-medium">
                        {title}
                    </p>
                    <p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white leading-tight">
                        {value}
                    </p>
                    {subtitle && (
                        <p className="mt-1 text-xs text-slate-400 dark:text-slate-500 flex items-center gap-1 leading-none">
                            <Icon size={14} />
                            {subtitle}
                        </p>
                    )}
                </div>
                <div className="p-3 rounded-xl bg-slate-100 dark:bg-slate-800">
                    <Icon
                        size={24}
                        className={colorClass}
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
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-sm">
            <div className="p-5 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/20">
                <div className="flex items-center gap-2">
                    <div className="p-2 rounded-lg bg-primary-100 dark:bg-primary-900/30">
                        <Icon
                            size={18}
                            className="text-primary-600 dark:text-primary-400"
                        />
                    </div>
                    <div>
                        <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 uppercase tracking-tight leading-none mb-1">
                            {title}
                        </h3>
                        {subtitle && (
                            <p className="text-xs text-slate-500 dark:text-slate-400 leading-none">
                                {subtitle}
                            </p>
                        )}
                    </div>
                </div>
            </div>
            <div className="p-5">
                {children || (
                    <div className="flex h-32 items-center justify-center text-sm text-slate-400 dark:text-slate-500 italic">
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
    todayCashRevenue,
    todayDigitalRevenue,
    topProducts = [],
    recentTransactions = [],
    topCustomers = [],
    expiredProducts = [], 
    expiringProducts = []
}) {
    const trendChartRef = useRef(null);
    const methodChartRef = useRef(null);
    const trendInstance = useRef(null);
    const methodInstance = useRef(null);

    const chartData = useMemo(() => revenueTrend ?? [], [revenueTrend]);

    useEffect(() => {
        // --- CHART 1: TREN PENDAPATAN (LINE) ---
        if (trendChartRef.current) {
            if (trendInstance.current) trendInstance.current.destroy();
            if (chartData.length > 0) {
                const ctx = trendChartRef.current.getContext("2d");
                const gradient = ctx.createLinearGradient(0, 0, 0, 200);
                gradient.addColorStop(0, "rgba(99, 102, 241, 0.3)");
                gradient.addColorStop(1, "rgba(99, 102, 241, 0.01)");

                trendInstance.current = new Chart(trendChartRef.current, {
                    type: "line",
                    data: {
                        labels: chartData.map((item) => item.label),
                        datasets: [{
                            label: "Pendapatan",
                            data: chartData.map((item) => item.total),
                            borderColor: "#6366f1",
                            backgroundColor: gradient,
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { ticks: { callback: (v) => formatCurrency(v), font: { size: 10 } }, grid: { display: false } },
                            x: { ticks: { font: { size: 10 } }, grid: { display: false } }
                        }
                    }
                });
            }
        }

        // --- CHART 2: BREAKDOWN METODE (DOUGHNUT) ---
        if (methodChartRef.current) {
            if (methodInstance.current) methodInstance.current.destroy();
            methodInstance.current = new Chart(methodChartRef.current, {
                type: "doughnut",
                data: {
                    labels: ['Tunai', 'Digital'],
                    datasets: [{
                        data: [todayCashRevenue, todayDigitalRevenue],
                        backgroundColor: ['#10b981', '#3b82f6'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11, weight: 'bold' }, padding: 20 } },
                        tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${formatCurrency(ctx.raw)}` } }
                    }
                }
            });
        }

        return () => {
            trendInstance.current?.destroy();
            methodInstance.current?.destroy();
        };
    }, [chartData, todayCashRevenue, todayDigitalRevenue]);

    const renderMethodBadge = (method) => {
        const m = method?.toLowerCase();
        if (m === 'cash') return <span className="flex items-center gap-1 text-[9px] font-black text-emerald-600 bg-emerald-50 dark:bg-emerald-950/30 px-2 py-0.5 rounded uppercase border border-emerald-100 dark:border-emerald-900"><IconCash size={10}/> Tunai</span>;
        return <span className="flex items-center gap-1 text-[9px] font-black text-blue-600 bg-blue-50 dark:bg-blue-950/30 px-2 py-0.5 rounded uppercase border border-blue-100 dark:border-blue-900"><IconDeviceMobileVibration size={10}/> Digital</span>;
    };

    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6 pb-10">
                {/* Header Row */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white uppercase italic tracking-tight">
                            Dashboard
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 font-medium">
                            Ringkasan aktivitas bisnis Anda hari ini
                        </p>
                    </div>
                    <Link
                        href={route("transactions.index")}
                        className="inline-flex items-center gap-2 px-6 py-3 rounded-2xl bg-primary-600 hover:bg-primary-700 text-white text-xs font-black uppercase transition-all shadow-lg shadow-primary-500/30 active:scale-95"
                    >
                        <IconShoppingCart size={18} />
                        <span>Buka Kasir</span>
                    </Link>
                </div>

                {/* Main Stat Cards Row 1 */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <StatCard title="Total Omzet" value={formatCurrency(totalRevenue)} subtitle="Seluruh Waktu" icon={IconMoneybag} gradient="from-indigo-600 to-indigo-800" />
                    <StatCard title="Total Profit" value={formatCurrency(totalProfit)} subtitle="Keuntungan Bersih" icon={IconTrendingUp} gradient="from-emerald-600 to-emerald-800" trend="up" />
                    <StatCard title="Rata-Rata" value={formatCurrency(averageOrder)} subtitle="Nilai Per Struk" icon={IconReceipt} gradient="from-blue-600 to-blue-800" />
                    <StatCard title="Antrean" value={todayTransactions} subtitle="Pesanan Hari Ini" icon={IconClock} gradient="from-orange-500 to-orange-700" />
                </div>

                {/* --- SEKSI GRAFIK METODE PEMBAYARAN HARI INI --- */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2">
                        <div className="h-full rounded-[2rem] bg-slate-900 p-8 text-white shadow-2xl flex flex-col md:flex-row items-center gap-8 border border-slate-800 relative overflow-hidden group">
                            <div className="absolute top-0 right-0 p-4 opacity-5 group-hover:scale-110 transition-transform">
                                <IconChartPie size={120} />
                            </div>
                            
                            <div className="flex-1 z-10 text-center md:text-left">
                                <h4 className="text-xs font-black uppercase text-slate-400 tracking-[0.3em] mb-4">Metode Pembayaran Hari Ini</h4>
                                <p className="text-4xl font-black italic tracking-tighter text-primary-400 mb-2">
                                    {formatCurrency(todayCashRevenue + todayDigitalRevenue)}
                                </p>
                                <div className="flex flex-wrap justify-center md:justify-start gap-4 mt-6">
                                    <div className="flex items-center gap-2">
                                        <div className="w-3 h-3 rounded-full bg-emerald-500" />
                                        <span className="text-[10px] font-black uppercase text-slate-300">Tunai: {formatCurrency(todayCashRevenue)}</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div className="w-3 h-3 rounded-full bg-blue-500" />
                                        <span className="text-[10px] font-black uppercase text-slate-300">Digital: {formatCurrency(todayDigitalRevenue)}</span>
                                    </div>
                                </div>
                            </div>

                            <div className="w-48 h-48 shrink-0 z-10">
                                <canvas ref={methodChartRef} />
                            </div>
                        </div>
                    </div>
                    
                    {/* Ringkasan Master Data */}
                    <div className="grid grid-cols-2 gap-4">
                        <InfoCard title="Kategori" value={totalCategories} icon={IconCategory} />
                        <InfoCard title="Produk" value={totalProducts} icon={IconBox} />
                        <InfoCard title="Transaksi" value={totalTransactions} icon={IconMoneybag} />
                        <InfoCard title="Pengguna" value={totalUsers} icon={IconUsers} />
                    </div>
                </div>

                {/* Expired Notifications Section */}
                {(expiredProducts.length > 0 || expiringProducts.length > 0) && (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {expiredProducts.length > 0 && (
                            <div className="bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800 rounded-3xl p-6 shadow-sm">
                                <div className="flex items-center gap-3 mb-4">
                                    <div className="p-3 bg-red-100 dark:bg-red-900/30 rounded-2xl text-red-600 dark:text-red-400"><IconAlertTriangle size={24} /></div>
                                    <div><h3 className="text-lg font-black text-red-800 dark:text-red-400 uppercase leading-none">Sudah Kadaluarsa!</h3><p className="text-xs text-red-600 dark:text-red-300 font-bold uppercase italic opacity-70 mt-1">Segera tarik produk ini.</p></div>
                                </div>
                                <div className="space-y-2 max-h-48 overflow-y-auto pr-2 custom-scrollbar">
                                    {expiredProducts.map((p, idx) => (
                                        <div key={idx} className="flex justify-between items-center bg-white dark:bg-slate-800 p-3 rounded-xl border border-red-100 dark:border-red-900/30 shadow-sm"><span className="text-sm font-bold text-slate-700 dark:text-slate-200 truncate uppercase">{p.title}</span><span className="text-[10px] font-black text-white px-2 py-1 bg-red-600 rounded-lg">{p.expired_date}</span></div>
                                    ))}
                                </div>
                            </div>
                        )}
                        {expiringProducts.length > 0 && (
                            <div className="bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800 rounded-3xl p-6 shadow-sm">
                                <div className="flex items-center gap-3 mb-4">
                                    <div className="p-3 bg-amber-100 dark:bg-amber-900/30 rounded-2xl text-amber-600 dark:text-amber-400"><IconCalendarTime size={24} /></div>
                                    <div><h3 className="text-lg font-black text-amber-800 dark:text-amber-400 uppercase leading-none">Hampir Kadaluarsa</h3><p className="text-xs text-amber-600 dark:text-amber-300 font-bold uppercase italic opacity-70 mt-1">Masa simpan &lt; 30 hari.</p></div>
                                </div>
                                <div className="space-y-2 max-h-48 overflow-y-auto pr-2 custom-scrollbar">
                                    {expiringProducts.map((p, idx) => (
                                        <div key={idx} className="flex justify-between items-center bg-white dark:bg-slate-800 p-3 rounded-xl border border-amber-100 dark:border-amber-900/30 shadow-sm"><span className="text-sm font-bold text-slate-700 dark:text-slate-200 truncate uppercase">{p.title}</span><span className="text-[10px] font-black text-amber-700 dark:text-amber-500 px-2 py-1 bg-amber-100 dark:bg-amber-900/40 rounded-lg">{p.expired_date}</span></div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* Charts and Lists Row */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <ListCard title="Tren Pendapatan" subtitle="12 data terakhir" icon={IconChartBar} emptyMessage="Belum ada data pendapatan">
                        {chartData.length > 0 && <div className="h-64"><canvas ref={trendChartRef} /></div>}
                    </ListCard>
                    <ListCard title="Produk Terlaris" subtitle="Berdasarkan volume penjualan" icon={IconBox} emptyMessage="Belum ada data produk">
                        {topProducts.length > 0 && (
                            <ul className="space-y-3">
                                {topProducts.map((product, index) => (
                                    <li key={index} className="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-transparent hover:border-slate-200 dark:hover:border-slate-700 transition-all">
                                        <div className="flex items-center gap-3">
                                            <span className="w-6 h-6 rounded-lg bg-primary-600 text-white text-[10px] font-black flex items-center justify-center italic">#{index + 1}</span>
                                            <div><p className="text-sm font-bold text-slate-800 dark:text-slate-200 uppercase leading-none">{product.name}</p><p className="text-[10px] text-slate-500 font-bold mt-1 uppercase tracking-tighter">{product.qty} Unit Terjual</p></div>
                                        </div>
                                        <p className="text-sm font-black text-primary-600 italic">{formatCurrency(product.total)}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </ListCard>
                </div>

                {/* Bottom Row */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <ListCard title="Transaksi Terbaru" subtitle="Validasi aktivitas kasir" icon={IconReceipt} emptyMessage="Belum ada transaksi">
                        {recentTransactions.length > 0 && (
                            <div className="space-y-3">
                                {recentTransactions.map((trx, index) => (
                                    <div key={index} className="flex items-center justify-between p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800 hover:shadow-md transition-all">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 mb-1">
                                                <p className="text-sm font-black text-slate-800 dark:text-slate-200 tracking-tighter uppercase">{trx.invoice}</p>
                                                {renderMethodBadge(trx.method)}
                                            </div>
                                            <p className="text-[10px] text-slate-500 font-bold uppercase">{trx.date} • {trx.customer}</p>
                                            <p className="text-[9px] text-slate-400 font-medium uppercase italic mt-0.5">Oleh: {trx.cashier}</p>
                                        </div>
                                        <div className="text-right ml-4"><p className="text-base font-black text-primary-600 italic tracking-tighter">{formatCurrency(trx.total)}</p></div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </ListCard>

                    <ListCard title="Pelanggan Loyal" subtitle="Berdasarkan total belanja" icon={IconUsers} emptyMessage="Belum ada pelanggan">
                        {topCustomers.length > 0 && (
                            <ul className="space-y-3">
                                {topCustomers.map((customer, index) => (
                                    <li key={index} className="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-transparent hover:border-slate-200 transition-all">
                                        <div className="flex items-center gap-3">
                                            <div className="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center text-white text-sm font-black shadow-md italic">{customer.name.charAt(0).toUpperCase()}</div>
                                            <div><p className="text-sm font-bold text-slate-800 dark:text-slate-200 uppercase leading-none">{customer.name}</p><p className="text-[10px] text-slate-500 font-bold mt-1 uppercase tracking-tighter">{customer.orders} Transaksi</p></div>
                                        </div>
                                        <p className="text-sm font-black text-slate-900 dark:text-white italic">{formatCurrency(customer.total)}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </ListCard>
                </div>
            </div>
            
            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 20px; }
            ` }} />
        </>
    );
}

Dashboard.layout = (page) => <DashboardLayout children={page} />;