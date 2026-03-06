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
    IconChartPie,
    IconLayoutDashboard,
    IconUser
} from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

// Stat Card Component - Updated to Modern Indigo
function StatCard({ title, value, subtitle, icon: Icon, gradient, trend }) {
    return (
        <div className={`relative overflow-hidden rounded-[2rem] p-6 bg-gradient-to-br ${gradient} text-white shadow-xl transition-transform hover:scale-[1.02] duration-300`}>
            {/* Background Pattern */}
            <div className="absolute top-0 right-0 w-32 h-32 opacity-10">
                <Icon
                    size={128}
                    strokeWidth={0.5}
                    className="transform translate-x-8 -translate-y-8"
                />
            </div>

            <div className="relative z-10">
                <div className="flex items-center gap-3 mb-4">
                    <div className="p-2.5 rounded-2xl bg-white/20 backdrop-blur-md border border-white/10">
                        <Icon size={22} strokeWidth={1.5} />
                    </div>
                    <span className="text-xs font-black uppercase tracking-widest opacity-90">
                        {title}
                    </span>
                </div>
                <p className="text-3xl font-black tracking-tighter">{value}</p>
                {subtitle && (
                    <p className="mt-3 text-[10px] font-bold uppercase tracking-tight opacity-70 flex items-center gap-1.5 leading-none">
                        {trend === "up" && <div className="p-0.5 bg-white/20 rounded-full"><IconArrowUpRight size={12} /></div>}
                        {trend === "down" && <div className="p-0.5 bg-white/20 rounded-full"><IconArrowDownRight size={12} /></div>}
                        {subtitle}
                    </p>
                )}
            </div>
        </div>
    );
}

// Info Card Component - Updated to Indigo Hover
function InfoCard({ title, value, subtitle, icon: Icon, colorClass = "text-indigo-600 dark:text-indigo-400" }) {
    return (
        <div className="rounded-[1.5rem] border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 transition-all hover:shadow-lg hover:border-indigo-300 dark:hover:border-indigo-900 group">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest">
                        {title}
                    </p>
                    <p className="mt-1 text-2xl font-black text-slate-900 dark:text-white leading-tight tracking-tighter">
                        {value}
                    </p>
                    {subtitle && (
                        <p className="mt-1 text-[10px] text-slate-400 dark:text-slate-500 flex items-center gap-1 leading-none font-bold uppercase">
                            <Icon size={12} />
                            {subtitle}
                        </p>
                    )}
                </div>
                <div className="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800 group-hover:bg-indigo-50 dark:group-hover:bg-indigo-950/30 transition-colors">
                    <Icon
                        size={24}
                        className={`${colorClass} transition-transform group-hover:scale-110`}
                        strokeWidth={1.5}
                    />
                </div>
            </div>
        </div>
    );
}

// List Card Component - Updated Indigo Header
function ListCard({ title, subtitle, icon: Icon, children, emptyMessage }) {
    return (
        <div className="rounded-[2rem] border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-sm">
            <div className="p-6 border-b border-slate-100 dark:border-slate-800 bg-slate-50/30 dark:bg-slate-800/20">
                <div className="flex items-center gap-3">
                    <div className="p-2.5 rounded-xl bg-indigo-100 dark:bg-indigo-900/30">
                        <Icon
                            size={20}
                            className="text-indigo-600 dark:text-indigo-400"
                        />
                    </div>
                    <div>
                        <h3 className="text-xs font-black text-slate-800 dark:text-slate-100 uppercase tracking-[0.15em] leading-none mb-1.5">
                            {title}
                        </h3>
                        {subtitle && (
                            <p className="text-[10px] text-slate-500 dark:text-slate-400 font-bold uppercase tracking-tight leading-none">
                                {subtitle}
                            </p>
                        )}
                    </div>
                </div>
            </div>
            <div className="p-6">
                {children || (
                    <div className="flex h-40 items-center justify-center text-sm text-slate-400 dark:text-slate-500 italic font-medium">
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
                gradient.addColorStop(0, "rgba(79, 70, 229, 0.3)"); // Indigo color
                gradient.addColorStop(1, "rgba(79, 70, 229, 0.01)");

                trendInstance.current = new Chart(trendChartRef.current, {
                    type: "line",
                    data: {
                        labels: chartData.map((item) => item.label),
                        datasets: [{
                            label: "Pendapatan",
                            data: chartData.map((item) => item.total),
                            borderColor: "#4f46e5", // Indigo-600
                            backgroundColor: gradient,
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                            pointBackgroundColor: "#4f46e5",
                            pointBorderColor: "#fff",
                            pointBorderWidth: 2,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { ticks: { callback: (v) => formatCurrency(v), font: { size: 10, weight: 'bold' } }, grid: { display: false } },
                            x: { ticks: { font: { size: 10, weight: 'bold' } }, grid: { display: false } }
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
                        backgroundColor: ['#10b981', '#4f46e5'], // Emerald & Indigo
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '75%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { 
                            backgroundColor: '#0f172a',
                            titleFont: { size: 11, weight: 'bold' },
                            bodyFont: { size: 12, weight: 'black' },
                            padding: 12,
                            cornerRadius: 12,
                            callbacks: { label: (ctx) => ` ${ctx.label}: ${formatCurrency(ctx.raw)}` } 
                        }
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
        return <span className="flex items-center gap-1 text-[9px] font-black text-indigo-600 bg-indigo-50 dark:bg-indigo-950/30 px-2 py-0.5 rounded uppercase border border-indigo-100 dark:border-indigo-900"><IconDeviceMobileVibration size={10}/> Digital</span>;
    };

    return (
        <>
            <Head title="Dashboard | POS SYSTEM AJA" />

            <div className="space-y-6 pb-10">
                {/* Header Row */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white uppercase italic tracking-tighter flex items-center gap-3">
                            <div className="p-2 bg-indigo-600 rounded-xl text-white shadow-lg shadow-indigo-500/20">
                                <IconLayoutDashboard size={24} />
                            </div>
                            Dashboard
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 font-medium mt-1">
                            Ringkasan aktivitas bisnis Anda hari ini
                        </p>
                    </div>
                    <Link
                        href={route("transactions.index")}
                        className="inline-flex items-center gap-2 px-8 py-3.5 rounded-[1.25rem] bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black uppercase tracking-widest transition-all shadow-lg shadow-indigo-500/30 active:scale-95"
                    >
                        <IconShoppingCart size={18} />
                        <span>Buka Kasir</span>
                    </Link>
                </div>

                {/* Main Stat Cards Row 1 */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    <StatCard title="Total Omzet" value={formatCurrency(totalRevenue)} subtitle="Akumulasi Seluruh Waktu" icon={IconMoneybag} gradient="from-indigo-600 to-indigo-800" />
                    <StatCard title="Total Profit" value={formatCurrency(totalProfit)} subtitle="Laba Bersih Valid" icon={IconTrendingUp} gradient="from-emerald-600 to-emerald-800" trend="up" />
                    <StatCard title="Rata-Rata" value={formatCurrency(averageOrder)} subtitle="Nilai Transaksi Per Struk" icon={IconReceipt} gradient="from-slate-800 to-slate-950" />
                    <StatCard title="Total Antrean" value={todayTransactions} subtitle="Pesanan Masuk Hari Ini" icon={IconClock} gradient="from-orange-500 to-orange-700" />
                </div>

                {/* --- SEKSI GRAFIK METODE PEMBAYARAN HARI INI --- */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2">
                        <div className="h-full rounded-[2.5rem] bg-slate-950 p-10 text-white shadow-2xl flex flex-col md:flex-row items-center gap-10 border border-slate-800/50 relative overflow-hidden group">
                            <div className="absolute top-0 right-0 p-6 opacity-[0.03] group-hover:scale-110 transition-transform duration-700">
                                <IconChartPie size={280} />
                            </div>
                            
                            <div className="flex-1 z-10 text-center md:text-left">
                                <div className="inline-flex items-center gap-2 px-3 py-1 bg-white/5 rounded-full border border-white/10 mb-6">
                                    <div className="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></div>
                                    <span className="text-[9px] font-black uppercase tracking-[0.2em] text-slate-400">Payment Breakdown Today</span>
                                </div>
                                <h4 className="text-sm font-black uppercase text-slate-400 tracking-widest mb-2">Total Pendapatan Hari Ini</h4>
                                <p className="text-5xl font-black italic tracking-tighter text-indigo-400 mb-8 drop-shadow-xl">
                                    {formatCurrency(todayCashRevenue + todayDigitalRevenue)}
                                </p>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-md">
                                    <div className="p-4 rounded-2xl bg-white/5 border border-white/5 backdrop-blur-md">
                                        <div className="flex items-center gap-2 mb-1">
                                            <div className="w-2 h-2 rounded-full bg-emerald-500" />
                                            <span className="text-[10px] font-black uppercase text-slate-400">Transaksi Tunai</span>
                                        </div>
                                        <p className="text-lg font-black text-white">{formatCurrency(todayCashRevenue)}</p>
                                    </div>
                                    <div className="p-4 rounded-2xl bg-white/5 border border-white/5 backdrop-blur-md">
                                        <div className="flex items-center gap-2 mb-1">
                                            <div className="w-2 h-2 rounded-full bg-indigo-500" />
                                            <span className="text-[10px] font-black uppercase text-slate-400">Transaksi Digital</span>
                                        </div>
                                        <p className="text-lg font-black text-white">{formatCurrency(todayDigitalRevenue)}</p>
                                    </div>
                                </div>
                            </div>

                            <div className="w-56 h-56 shrink-0 z-10 relative">
                                <div className="absolute inset-0 flex items-center justify-center flex-col">
                                    <span className="text-xs font-black text-slate-500 uppercase">Today</span>
                                    <IconChartPie size={20} className="text-indigo-500 mt-1 opacity-50" />
                                </div>
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
                        <div className="col-span-2 mt-2">
                             <div className="p-5 rounded-[1.5rem] bg-indigo-600 text-white shadow-lg flex items-center justify-between group overflow-hidden relative">
                                <div className="absolute -right-4 -bottom-4 opacity-10 group-hover:rotate-12 transition-transform">
                                    <IconDeviceMobileVibration size={100} />
                                </div>
                                <div>
                                    <p className="text-[10px] font-black uppercase tracking-widest opacity-80">Online Integration</p>
                                    <p className="text-xl font-black italic">Cloud POS Ready</p>
                                </div>
                                <IconDeviceMobileVibration size={32} strokeWidth={1.5} />
                             </div>
                        </div>
                    </div>
                </div>

                {/* Expired Notifications Section */}
                {(expiredProducts.length > 0 || expiringProducts.length > 0) && (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 animate-in fade-in slide-in-from-top-4 duration-500">
                        {expiredProducts.length > 0 && (
                            <div className="bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900/50 rounded-[2rem] p-8 shadow-sm relative overflow-hidden">
                                <div className="absolute top-0 right-0 p-4 opacity-5 rotate-12"><IconAlertTriangle size={120} /></div>
                                <div className="flex items-center gap-4 mb-6 relative z-10">
                                    <div className="p-4 bg-rose-500 rounded-[1.25rem] text-white shadow-lg shadow-rose-500/30">
                                        <IconAlertTriangle size={28} />
                                    </div>
                                    <div>
                                        <h3 className="text-xl font-black text-rose-800 dark:text-rose-400 uppercase leading-none tracking-tighter">Sudah Kadaluarsa!</h3>
                                        <p className="text-[10px] text-rose-600 dark:text-rose-300 font-bold uppercase italic opacity-70 mt-1.5 tracking-widest">Segera tarik produk dari rak penjualan.</p>
                                    </div>
                                </div>
                                <div className="space-y-3 max-h-56 overflow-y-auto pr-3 custom-scrollbar relative z-10">
                                    {expiredProducts.map((p, idx) => (
                                        <div key={idx} className="flex justify-between items-center bg-white dark:bg-slate-900 p-4 rounded-2xl border border-rose-100 dark:border-rose-900/30 shadow-sm transition-all hover:translate-x-1">
                                            <span className="text-sm font-black text-slate-800 dark:text-slate-200 truncate uppercase tracking-tight">{p.title}</span>
                                            <span className="text-[10px] font-black text-white px-3 py-1.5 bg-rose-600 rounded-lg shadow-md uppercase">{p.expired_date}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                        {expiringProducts.length > 0 && (
                            <div className="bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/50 rounded-[2rem] p-8 shadow-sm relative overflow-hidden">
                                <div className="absolute top-0 right-0 p-4 opacity-5 rotate-12"><IconCalendarTime size={120} /></div>
                                <div className="flex items-center gap-4 mb-6 relative z-10">
                                    <div className="p-4 bg-amber-500 rounded-[1.25rem] text-white shadow-lg shadow-amber-500/30">
                                        <IconCalendarTime size={28} />
                                    </div>
                                    <div>
                                        <h3 className="text-xl font-black text-amber-800 dark:text-amber-400 uppercase leading-none tracking-tighter">Hampir Kadaluarsa</h3>
                                        <p className="text-[10px] text-amber-600 dark:text-amber-300 font-bold uppercase italic opacity-70 mt-1.5 tracking-widest">Masa simpan kurang dari 30 hari.</p>
                                    </div>
                                </div>
                                <div className="space-y-3 max-h-56 overflow-y-auto pr-3 custom-scrollbar relative z-10">
                                    {expiringProducts.map((p, idx) => (
                                        <div key={idx} className="flex justify-between items-center bg-white dark:bg-slate-900 p-4 rounded-2xl border border-amber-100 dark:border-amber-900/30 shadow-sm transition-all hover:translate-x-1">
                                            <span className="text-sm font-black text-slate-800 dark:text-slate-200 truncate uppercase tracking-tight">{p.title}</span>
                                            <span className="text-[10px] font-black text-amber-700 dark:text-amber-500 px-3 py-1.5 bg-amber-100 dark:bg-amber-900/40 rounded-lg uppercase border border-amber-200/50">{p.expired_date}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* Charts and Lists Row */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <ListCard title="Tren Pendapatan Bisnis" subtitle="Analisis 12 periode terakhir" icon={IconChartBar} emptyMessage="Belum ada data pendapatan terkumpul">
                        {chartData.length > 0 && <div className="h-72"><canvas ref={trendChartRef} /></div>}
                    </ListCard>
                    <ListCard title="Produk Terlaris (Hero)" subtitle="Top performa berdasarkan volume" icon={IconBox} emptyMessage="Belum ada data produk terjual">
                        {topProducts.length > 0 && (
                            <ul className="space-y-4">
                                {topProducts.map((product, index) => (
                                    <li key={index} className="flex items-center justify-between p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800 hover:border-indigo-200 dark:hover:border-indigo-900 transition-all hover:bg-white dark:hover:bg-slate-800 group">
                                        <div className="flex items-center gap-4">
                                            <span className="w-8 h-8 rounded-xl bg-indigo-600 text-white text-[11px] font-black flex items-center justify-center italic shadow-lg shadow-indigo-500/20 transition-transform group-hover:scale-110">#{index + 1}</span>
                                            <div>
                                                <p className="text-sm font-black text-slate-800 dark:text-slate-100 uppercase leading-none tracking-tight">{product.name}</p>
                                                <p className="text-[10px] text-slate-500 dark:text-slate-400 font-bold mt-1.5 uppercase tracking-widest">{product.qty} Unit Berhasil Terjual</p>
                                            </div>
                                        </div>
                                        <p className="text-base font-black text-indigo-600 dark:text-indigo-400 italic tracking-tighter">{formatCurrency(product.total)}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </ListCard>
                </div>

                {/* Bottom Row */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <ListCard title="Transaksi Terbaru" subtitle="Log aktivitas operasional kasir" icon={IconReceipt} emptyMessage="Belum ada transaksi tersimpan">
                        {recentTransactions.length > 0 && (
                            <div className="space-y-3">
                                {recentTransactions.map((trx, index) => (
                                    <div key={index} className="flex items-center justify-between p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800 hover:shadow-lg hover:border-indigo-100 transition-all hover:bg-white dark:hover:bg-slate-800">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-3 mb-2">
                                                <p className="text-sm font-black text-slate-900 dark:text-white tracking-tighter uppercase">{trx.invoice}</p>
                                                {renderMethodBadge(trx.method)}
                                            </div>
                                            <div className="flex items-center gap-2 text-[10px] text-slate-500 font-bold uppercase tracking-tight">
                                                <IconCalendarTime size={12} /> {trx.date} 
                                                <span className="text-slate-300 mx-1">|</span>
                                                <IconUser size={12} /> {trx.customer}
                                            </div>
                                            <p className="text-[9px] text-indigo-500 dark:text-indigo-400 font-black uppercase italic mt-1 tracking-widest opacity-80">Admin: {trx.cashier}</p>
                                        </div>
                                        <div className="text-right ml-4">
                                            <p className="text-lg font-black text-indigo-600 dark:text-indigo-400 italic tracking-tighter drop-shadow-sm">
                                                {formatCurrency(trx.total)}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </ListCard>

                    <ListCard title="Pelanggan Loyal" subtitle="Analisis retensi belanja" icon={IconUsers} emptyMessage="Belum ada database pelanggan">
                        {topCustomers.length > 0 && (
                            <ul className="space-y-4">
                                {topCustomers.map((customer, index) => (
                                    <li key={index} className="flex items-center justify-between p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800 hover:shadow-lg transition-all hover:bg-white dark:hover:bg-slate-800 group">
                                        <div className="flex items-center gap-4">
                                            <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center text-white text-lg font-black shadow-lg shadow-indigo-500/20 italic group-hover:rotate-3 transition-transform">
                                                {customer.name.charAt(0).toUpperCase()}
                                            </div>
                                            <div>
                                                <p className="text-sm font-black text-slate-800 dark:text-slate-200 uppercase tracking-tight">{customer.name}</p>
                                                <p className="text-[10px] text-slate-500 font-bold mt-1 uppercase tracking-[0.2em]">{customer.orders} Total Transaksi</p>
                                            </div>
                                        </div>
                                        <p className="text-base font-black text-slate-900 dark:text-white italic tracking-tighter">{formatCurrency(customer.total)}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </ListCard>
                </div>
            </div>
            
            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: #4f46e5; border-radius: 20px; }
                .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
            ` }} />
        </>
    );
}

Dashboard.layout = (page) => <DashboardLayout children={page} />;