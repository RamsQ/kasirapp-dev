import React, { useState, useEffect, useMemo } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { 
    ResponsiveContainer, AreaChart, Area, XAxis, YAxis, 
    CartesianGrid, Tooltip, Legend, BarChart, Bar, Cell 
} from "recharts";
import {
    IconCash, IconPlus, IconCalendar, IconSearch,
    IconTrendingUp, IconReceipt2, IconTable,
    IconReceipt, IconUser, IconClock, IconChartBar, 
    IconPhoto, IconLayoutDashboard, IconWallet, IconArrowUpRight, IconPackage,
    IconAlertTriangle, IconFilter, IconArrowDownRight, IconGift, IconWorld,
    IconQrcode, IconCreditCard, IconBuildingBank, IconTag, IconRosetteDiscount,
    IconTicket, IconChartPie, IconChartArrows, IconHourglassHigh, IconFlame
} from "@tabler/icons-react";

const formatCurrency = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value || 0);

const FinanceReport = ({ auth, report }) => {
    const [activeTab, setActiveTab] = useState('laba-rugi');
    const [startDate, setStartDate] = useState(report.filter.start || "");
    const [endDate, setEndDate] = useState(report.filter.end || "");
    const [filterStaff, setFilterStaff] = useState(report.filter.user_id || "");

    const { data, setData, post, processing, reset, errors } = useForm({
        name: '', amount: '', date: new Date().toISOString().split('T')[0],
        image: null, source: 'Kas Laci', category: 'Operasional',
        capital_source: 'Setoran Pemilik', capital_amount: '',
    });

    const handleFilter = (e) => {
        e.preventDefault();
        router.get(route("report.finance"), 
            { start_date: startDate, end_date: endDate, user_id: filterStaff },
            { preserveState: true, replace: true }
        );
    };

    const submitExpense = (e) => {
        e.preventDefault();
        post(route('expenses.store'), { 
            forceFormData: true, 
            onSuccess: () => reset('name', 'amount', 'image', 'source', 'category') 
        });
    };

    // --- LOGIKA DATA ---
    const appCommissionTotal = report.summary?.app_expenses || 0;
    const cashRevenue = report.summary?.cash_revenue || 0;
    const digitalRevenue = report.summary?.digital_revenue || 0;

    const inventoryLossTotal = report.expenseList
        ?.filter(exp => exp.category === 'Kerugian Stok')
        .reduce((acc, curr) => acc + parseFloat(curr.amount), 0) || 0;

    const promotionExpenseTotal = report.expenseList
        ?.filter(exp => exp.category === 'Beban Promosi' || exp.name?.includes('PROMO:'))
        .reduce((acc, curr) => acc + parseFloat(curr.amount), 0) || 0;

    const generalOperationalTotal = report.expenses - inventoryLossTotal - promotionExpenseTotal - appCommissionTotal;

    // --- LOGIKA PROMOSI & GRAFIK ---
    const itemPromos = report.summary?.item_promos || [];
    const totalPromoGiven = report.summary?.total_promo || 0;
    const globalDiscountTotal = report.summary?.global_discount || 0;

    // Data Top 5 Produk untuk Grafik Batang (Tab Promosi)
    const chartPromoData = useMemo(() => {
        return [...itemPromos]
            .sort((a, b) => b.total_discount_value - a.total_discount_value)
            .slice(0, 5)
            .map(item => ({
                name: item.product_name.substring(0, 12),
                full_name: item.product_name,
                value: Math.round(item.total_discount_value)
            }));
    }, [itemPromos]);

    // --- LOGIKA WAKTU (Tab Waktu) ---
    const hourlyData = report.summary?.hourly_stats || [];
    const peakTransaction = useMemo(() => {
        if (hourlyData.length === 0) return 0;
        return Math.max(...hourlyData.map(o => o.count));
    }, [hourlyData]);

    return (
        <>
            <Head title="Laporan Keuangan" />
            <div className="space-y-6 text-slate-900 dark:text-slate-100 pb-10">
                
                {/* 1. Header & Tab Navigation */}
                <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 border-b border-slate-200 dark:border-slate-800 pb-2">
                    <div>
                        <h1 className="text-2xl font-black flex items-center gap-3 uppercase tracking-tight italic">
                            <div className="p-2 bg-cosmic rounded-xl text-white shadow-lg shadow-cosmic-main/20">
                                <IconWallet size={24} />
                            </div>
                            Finance <span className="text-cosmic-main not-italic text-sm ml-1 font-bold bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded-lg">Analytic Hub</span>
                        </h1>
                        <div className="flex gap-8 mt-6 overflow-x-auto scrollbar-hide">
                            {['laba-rugi', 'neraca', 'promosi', 'waktu', 'pengeluaran'].map((tab) => (
                                <button 
                                    key={tab}
                                    onClick={() => setActiveTab(tab)} 
                                    className={`pb-3 text-[10px] font-black uppercase tracking-[0.2em] transition-all border-b-4 shrink-0 ${
                                        activeTab === tab 
                                        ? 'border-cosmic-main text-cosmic-main' 
                                        : 'border-transparent text-slate-400 hover:text-slate-600 dark:hover:text-slate-200'
                                    }`}
                                >
                                    {tab.replace('-', ' ')}
                                </button>
                            ))}
                        </div>
                    </div>

                    {activeTab !== 'neraca' && (
                        <form onSubmit={handleFilter} className="flex flex-wrap items-center gap-2 bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm mb-2">
                            <div className="flex items-center gap-2 px-2">
                                <IconCalendar size={14} className="text-slate-400" />
                                <input type="date" className="border-none bg-transparent text-[10px] font-black focus:ring-0 p-1 dark:text-white uppercase" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
                                <span className="text-[10px] text-slate-300 dark:text-slate-700 font-black">S/D</span>
                                <input type="date" className="border-none bg-transparent text-[10px] font-black focus:ring-0 p-1 dark:text-white uppercase" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
                            </div>
                            <div className="h-4 w-[1px] bg-slate-200 dark:bg-slate-700 mx-1"></div>
                            <select value={filterStaff} onChange={(e) => setFilterStaff(e.target.value)} className="border-none bg-transparent text-[10px] font-black focus:ring-0 p-1 uppercase dark:text-white cursor-pointer">
                                <option value="" className="dark:bg-slate-900 font-black text-center">SEMUA STAFF</option>
                                {report.staffList?.map(s => <option key={s.id} value={s.id} className="dark:bg-slate-900 font-bold">{s.name.toUpperCase()}</option>)}
                            </select>
                            <button type="submit" className="bg-cosmic text-white p-2.5 rounded-xl transition-all active:scale-95 shadow-md shadow-cosmic-main/20 hover:bg-cosmic-main">
                                <IconSearch size={16} />
                            </button>
                        </form>
                    )}
                </div>

                {/* --- TAB CONTENT: LABA RUGI --- */}
                {activeTab === 'laba-rugi' && (
                    <div className="space-y-6 animate-in fade-in duration-500">
                        <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div className="bg-white dark:bg-slate-900 p-6 rounded-[2rem] border border-slate-200 dark:border-slate-800 flex items-center gap-5 shadow-sm group">
                                <div className="p-4 bg-blue-500 rounded-2xl text-white shadow-lg group-hover:rotate-6 transition-transform"><IconReceipt size={28}/></div>
                                <div>
                                    <p className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest leading-none mb-1">Total Omzet</p>
                                    <h3 className="text-xl font-black text-blue-600 dark:text-blue-400 tracking-tighter">{formatCurrency(report.revenue)}</h3>
                                </div>
                            </div>
                            <div className="bg-white dark:bg-slate-900 p-6 rounded-[2rem] border border-slate-200 dark:border-slate-800 flex items-center gap-5 shadow-sm group">
                                <div className="p-4 bg-orange-500 rounded-2xl text-white shadow-lg group-hover:rotate-6 transition-transform"><IconWorld size={28}/></div>
                                <div>
                                    <p className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest leading-none mb-1">Beban Komisi</p>
                                    <h3 className="text-xl font-black text-orange-600 dark:text-orange-400 tracking-tighter">{formatCurrency(appCommissionTotal)}</h3>
                                </div>
                            </div>
                            <div className="bg-white dark:bg-slate-900 p-6 rounded-[2rem] border border-slate-200 dark:border-slate-800 flex items-center gap-5 shadow-sm group">
                                <div className="p-4 bg-rose-500 rounded-2xl text-white shadow-lg group-hover:rotate-6 transition-transform"><IconReceipt2 size={28}/></div>
                                <div>
                                    <p className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest leading-none mb-1">Total Beban</p>
                                    <h3 className="text-xl font-black text-rose-600 dark:text-rose-400 tracking-tighter">{formatCurrency(report.expenses)}</h3>
                                </div>
                            </div>
                            <div className="bg-cosmic p-6 rounded-[2rem] flex items-center gap-5 text-white shadow-xl shadow-cosmic-main/20 group relative overflow-hidden">
                                <div className="p-4 bg-white/20 rounded-2xl z-10 group-hover:scale-110 transition-transform"><IconCash size={28} /></div>
                                <div className="z-10">
                                    <p className="text-[10px] font-black text-white/80 uppercase tracking-widest leading-none mb-1">Laba Bersih</p>
                                    <h3 className="text-2xl font-black tracking-tighter italic">{formatCurrency(report.netProfit)}</h3>
                                </div>
                                <IconTrendingUp size={100} className="absolute -bottom-4 -right-4 opacity-10" />
                            </div>
                        </div>

                        {/* ARUS KEUANGAN */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="bg-emerald-50/50 dark:bg-emerald-950/10 border border-emerald-100 dark:border-emerald-900/40 p-6 rounded-[2rem] flex items-center justify-between shadow-sm">
                                <div className="flex items-center gap-4">
                                    <div className="p-3 bg-emerald-500 text-white rounded-2xl shadow-md"><IconCash size={24} /></div>
                                    <div>
                                        <h4 className="text-[10px] font-black text-emerald-600 dark:text-emerald-400 uppercase tracking-widest leading-none mb-1">Saldo Tunai (Laci)</h4>
                                        <p className="text-lg font-black text-slate-800 dark:text-slate-200 tracking-tighter">{formatCurrency(cashRevenue)}</p>
                                    </div>
                                </div>
                                <div className="text-right">
                                    <span className="text-[9px] font-black bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 px-2 py-1 rounded-lg uppercase shadow-inner">Wajib Ada di Laci</span>
                                </div>
                            </div>
                            <div className="bg-blue-50/50 dark:bg-blue-950/10 border border-blue-100 dark:border-blue-900/40 p-6 rounded-[2rem] flex items-center justify-between shadow-sm">
                                <div className="flex items-center gap-4">
                                    <div className="p-3 bg-blue-500 text-white rounded-2xl shadow-md"><IconBuildingBank size={24} /></div>
                                    <div>
                                        <h4 className="text-[10px] font-black text-blue-600 dark:text-blue-400 uppercase tracking-widest leading-none mb-1">Saldo Digital (Bank)</h4>
                                        <p className="text-lg font-black text-slate-800 dark:text-slate-200 tracking-tighter">{formatCurrency(digitalRevenue)}</p>
                                    </div>
                                </div>
                                <div className="text-right flex flex-col items-end gap-1">
                                    <div className="flex gap-2 text-[8px] font-black text-slate-400 uppercase">
                                        <span className="flex items-center gap-1"><IconQrcode size={10}/> QRIS STATIS</span>
                                        <span className="flex items-center gap-1"><IconCreditCard size={10}/> TRANSFER</span>
                                    </div>
                                    <span className="text-[9px] font-black bg-blue-100 dark:bg-blue-900/40 text-blue-600 px-2 py-1 rounded-lg uppercase shadow-inner">Cek Mutasi Rekening</span>
                                </div>
                            </div>
                        </div>

                        {/* Financial Statement Table */}
                        <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-10 shadow-sm relative overflow-hidden">
                            <h3 className="font-black text-[10px] uppercase tracking-[0.2em] text-slate-400 mb-8 italic">Statement of Profit and Loss Analysis</h3>
                            <div className="space-y-5 text-sm font-bold relative z-10">
                                <div className="flex justify-between text-slate-500 dark:text-slate-400 uppercase tracking-tighter"><span>Gross Revenue (Total Penjualan Kotor)</span><span>{formatCurrency(report.revenue)}</span></div>
                                <div className="flex justify-between text-rose-500 dark:text-rose-400 uppercase tracking-tighter"><span>COGS / HPP (Modal Barang Terjual)</span><span>({formatCurrency(report.hpp)})</span></div>
                                <div className="flex justify-between items-center py-3 px-5 bg-orange-50/40 dark:bg-orange-950/10 border border-dashed border-orange-200 dark:border-orange-900/40 rounded-2xl">
                                    <div className="flex items-center gap-3">
                                        <IconWorld className="text-orange-500" size={20} />
                                        <div><span className="text-orange-600 dark:text-orange-400 uppercase tracking-tighter block leading-none mb-1 font-black">Beban Komisi Aplikasi Online</span><span className="text-[8px] text-orange-400 font-black uppercase">Markup & Fee Layanan Online</span></div>
                                    </div>
                                    <span className="text-orange-600 font-black tracking-tighter">({formatCurrency(appCommissionTotal)})</span>
                                </div>
                                <div className="pt-5 border-t border-slate-100 dark:border-slate-800 flex justify-between font-black text-blue-600 dark:text-blue-400 text-2xl tracking-tighter italic"><span>GROSS PROFIT (LABA KOTOR)</span><span>{formatCurrency(report.grossProfit)}</span></div>
                                <div className="space-y-3 pl-6 border-l-2 border-slate-100 dark:border-slate-800 mt-4">
                                    <div className="flex justify-between text-slate-400 italic text-xs font-bold uppercase tracking-tighter"><span>Beban Operasional & Gaji</span><span>({formatCurrency(generalOperationalTotal)})</span></div>
                                    <div className="flex justify-between text-orange-500 italic text-xs font-black uppercase tracking-tighter"><span className="flex items-center gap-1"><IconAlertTriangle size={12}/> Beban Penurunan Nilai Stok (Expired/Opname)</span><span>({formatCurrency(inventoryLossTotal)})</span></div>
                                    <div className="flex justify-between text-emerald-500 italic text-xs font-black uppercase tracking-tighter"><span className="flex items-center gap-1"><IconGift size={12}/> Beban Promosi (Bonus Produk/Free Items)</span><span>({formatCurrency(promotionExpenseTotal)})</span></div>
                                </div>
                                <div className="pt-8 border-t-4 border-double border-slate-200 dark:border-slate-800 flex justify-between font-black text-emerald-600 dark:text-emerald-400 text-3xl tracking-tighter italic"><span>NET PROFIT (LABA BERSIH)</span><span>{formatCurrency(report.netProfit)}</span></div>
                            </div>
                        </div>
                    </div>
                )}

                {/* --- TAB CONTENT: PROMOSI --- */}
                {activeTab === 'promosi' && (
                    <div className="animate-in fade-in duration-500 space-y-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div className="bg-white dark:bg-slate-900 p-6 rounded-[2rem] border border-slate-200 dark:border-slate-800 flex items-center gap-5 shadow-sm">
                                <div className="p-4 bg-emerald-500 rounded-2xl text-white shadow-lg"><IconTag size={28}/></div>
                                <div><p className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest leading-none mb-1">Potongan Grosir</p><h3 className="text-xl font-black text-emerald-600 dark:text-emerald-400 tracking-tighter">{formatCurrency(totalPromoGiven - globalDiscountTotal)}</h3></div>
                            </div>
                            <div className="bg-white dark:bg-slate-900 p-6 rounded-[2rem] border border-slate-200 dark:border-slate-800 flex items-center gap-5 shadow-sm">
                                <div className="p-4 bg-blue-500 rounded-2xl text-white shadow-lg"><IconTicket size={28}/></div>
                                <div><p className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest leading-none mb-1">Potongan Global</p><h3 className="text-xl font-black text-blue-600 dark:text-blue-400 tracking-tighter">{formatCurrency(globalDiscountTotal)}</h3></div>
                            </div>
                            <div className="bg-cosmic p-6 rounded-[2rem] flex items-center gap-5 text-white shadow-xl shadow-cosmic-main/20 group relative overflow-hidden">
                                <div className="p-4 bg-white/20 rounded-2xl z-10"><IconRosetteDiscount size={28} /></div>
                                <div className="z-10"><p className="text-[10px] font-black text-white/80 uppercase tracking-widest leading-none mb-1">Anggaran Promo</p><h3 className="text-2xl font-black tracking-tighter italic">{formatCurrency(totalPromoGiven)}</h3></div>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div className="lg:col-span-2">
                                <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-8 shadow-sm h-[400px] flex flex-col">
                                    <div className="flex items-center justify-between mb-8 leading-none">
                                        <div><h3 className="font-black text-sm uppercase tracking-tight dark:text-white">Top 5 Produk (Diskon Terbesar)</h3><p className="text-[10px] text-slate-500 font-bold uppercase mt-1 italic">Nilai Rupiah Penghematan Pelanggan</p></div>
                                        <IconChartArrows className="text-slate-300" size={32} />
                                    </div>
                                    <div className="flex-1 w-full">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={chartPromoData} layout="vertical" margin={{ left: 20, right: 30 }}>
                                                <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="#e2e8f0" opacity={0.3} />
                                                <XAxis type="number" hide />
                                                <YAxis dataKey="name" type="category" width={80} tick={{ fontSize: 10, fontWeight: 'bold', fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                                <Tooltip 
                                                    cursor={{ fill: 'transparent' }} 
                                                    content={({ active, payload }) => {
                                                        if (active && payload && payload.length) {
                                                            return (
                                                                <div className="bg-slate-900 text-white p-3 rounded-xl border border-slate-700 shadow-2xl">
                                                                    <p className="text-[10px] font-black uppercase mb-1">{payload[0].payload.full_name}</p>
                                                                    <p className="text-sm font-black text-emerald-400">{formatCurrency(payload[0].value)}</p>
                                                                </div>
                                                            );
                                                        }
                                                        return null;
                                                    }}
                                                />
                                                <Bar dataKey="value" radius={[0, 10, 10, 0]} barSize={30}>
                                                    {chartPromoData.map((entry, index) => (
                                                        <Cell key={`cell-${index}`} fill={index === 0 ? '#10b981' : '#6366f1'} fillOpacity={1 - (index * 0.15)} />
                                                    ))}
                                                </Bar>
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </div>
                            </div>

                            <div className="lg:col-span-1">
                                <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden h-full flex flex-col">
                                    <div className="p-8 border-b dark:border-slate-800"><h3 className="font-black text-sm uppercase tracking-tight dark:text-white leading-none">Rincian Per Item</h3></div>
                                    <div className="p-4 space-y-3 overflow-y-auto custom-scrollbar flex-1 max-h-[300px]">
                                        {itemPromos.map((promo, i) => (
                                            <div key={i} className="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 flex justify-between items-center border border-transparent hover:border-slate-200 transition-all group">
                                                <div><p className="text-[10px] font-black dark:text-white uppercase leading-none mb-1 group-hover:text-cosmic-main transition-colors">{promo.product_name}</p><p className="text-[9px] text-slate-500 font-bold uppercase tracking-tighter leading-none">{promo.total_qty} Unit Terjual</p></div>
                                                <div className="text-right font-black text-xs text-emerald-500 italic">-{formatCurrency(promo.total_discount_value)}</div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* --- TAB CONTENT: WAKTU (BARU) --- */}
                {activeTab === 'waktu' && (
                    <div className="animate-in fade-in duration-500 space-y-6">
                        <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-8 shadow-sm">
                            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-10 leading-none">
                                <div>
                                    <h3 className="font-black text-sm uppercase tracking-tight dark:text-white flex items-center gap-2">
                                        <IconHourglassHigh size={20} className="text-primary-500" />
                                        Analisis Jam Sibuk
                                    </h3>
                                    <p className="text-xs text-slate-500 italic mt-1 uppercase">Frekuensi jumlah transaksi berdasarkan waktu harian.</p>
                                </div>
                                <div className="px-4 py-2 bg-orange-50 dark:bg-orange-950/20 text-orange-600 rounded-xl border border-orange-100 dark:border-orange-900/40 flex items-center gap-2 animate-pulse">
                                    <IconFlame size={18} />
                                    <span className="text-[10px] font-black uppercase italic tracking-tighter">Puncak Penjualan: {peakTransaction} TRX / Jam</span>
                                </div>
                            </div>
                            
                            <div className="h-[400px] w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart data={hourlyData} margin={{ top: 10, right: 30, left: 0, bottom: 0 }}>
                                        <defs>
                                            <linearGradient id="colorCount" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#6366f1" stopOpacity={0.8}/>
                                                <stop offset="95%" stopColor="#6366f1" stopOpacity={0}/>
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e2e8f0" opacity={0.3} />
                                        <XAxis dataKey="hour" tick={{ fontSize: 10, fontWeight: 'bold', fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                        <YAxis tick={{ fontSize: 10, fontWeight: 'bold', fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                        <Tooltip 
                                            content={({ active, payload }) => {
                                                if (active && payload && payload.length) {
                                                    return (
                                                        <div className="bg-slate-900 text-white p-3 rounded-xl border border-slate-700 shadow-2xl">
                                                            <p className="text-[10px] font-black uppercase mb-1 tracking-widest italic">Pukul {payload[0].payload.hour}</p>
                                                            <p className="text-sm font-black text-primary-400 leading-none">{payload[0].value} Transaksi Sukses</p>
                                                        </div>
                                                    );
                                                }
                                                return null;
                                            }}
                                        />
                                        <Area type="monotone" dataKey="count" stroke="#6366f1" strokeWidth={4} fillOpacity={1} fill="url(#colorCount)" />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        </div>
                    </div>
                )}

                {/* --- TAB CONTENT: NERACA --- */}
                {activeTab === 'neraca' && (
                    <div className="animate-in fade-in duration-500 space-y-6">
                        <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 shadow-sm p-10">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-16">
                                <div className="space-y-8">
                                    <h4 className="flex items-center gap-3 text-[10px] font-black text-blue-600 dark:text-blue-400 uppercase tracking-[0.3em] border-b border-blue-50 dark:border-blue-900/30 pb-4 italic"><IconChartBar size={18}/> Aktiva (Aset & Harta)</h4>
                                    <div className="space-y-6 text-sm font-bold">
                                        {[
                                            { label: 'Kas di Laci (Tunai)', val: report.balanceSheet.cash_in_drawer, color: 'text-slate-600 dark:text-slate-300' },
                                            { label: 'Saldo Modal Bank (Digital)', val: report.balanceSheet.external_capital, color: 'text-emerald-600 dark:text-emerald-400' },
                                            { label: 'Nilai Inventori (Bahan & Produk)', val: report.balanceSheet.inventory_value, color: 'text-slate-600 dark:text-slate-300' },
                                        ].map((item, i) => (
                                            <div key={i} className="flex justify-between uppercase tracking-tighter">
                                                <span className="text-slate-400 dark:text-slate-500 font-black text-[11px]">{item.label}</span>
                                                <span className={item.color}>{formatCurrency(item.val)}</span>
                                            </div>
                                        ))}
                                        <div className="pt-8 border-t-4 border-blue-600 dark:border-blue-500 flex justify-between items-center text-blue-600 dark:text-blue-400 text-2xl font-black tracking-tighter italic italic"><span>TOTAL ASET</span><span>{formatCurrency(report.balanceSheet.total_assets)}</span></div>
                                    </div>
                                </div>
                                <div className="space-y-8">
                                    <h4 className="flex items-center gap-3 text-[10px] font-black text-emerald-600 dark:text-emerald-400 uppercase tracking-[0.3em] border-b border-emerald-50 dark:border-emerald-900/30 pb-4 italic"><IconReceipt size={18}/> Pasiva (Modal & Kewajiban)</h4>
                                    <div className="space-y-6 text-sm font-bold">
                                        {[
                                            { label: 'Hutang Dagang (Kewajiban)', val: report.balanceSheet.accounts_payable, color: 'text-rose-600 dark:text-rose-400' },
                                            { label: 'Modal Disetor Pemilik', val: report.balanceSheet.external_capital, color: 'text-slate-600 dark:text-slate-300' },
                                            { label: 'Akumulasi Laba Ditahan', val: report.balanceSheet.retained_earnings, color: 'text-slate-600 dark:text-slate-300' },
                                        ].map((item, i) => (
                                            <div key={i} className="flex justify-between uppercase tracking-tighter">
                                                <span className="text-slate-400 dark:text-slate-500 font-black text-[11px]">{item.label}</span>
                                                <span className={item.color}>{formatCurrency(item.val)}</span>
                                            </div>
                                        ))}
                                        <div className="pt-8 border-t-4 border-emerald-600 dark:border-emerald-500 flex justify-between items-center text-emerald-600 dark:text-emerald-400 text-2xl font-black tracking-tighter italic italic"><span>TOTAL PASIVA</span><span>{formatCurrency(report.balanceSheet.total_assets)}</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* --- TAB CONTENT: PENGELUARAN --- */}
                {activeTab === 'pengeluaran' && (
                    <div className="animate-in fade-in duration-500 grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <div className="lg:col-span-1">
                            <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden sticky top-24">
                                <div className="p-6 bg-cosmic flex items-center gap-3 border-b border-white/10"><IconPlus size={20} className="text-white" /><span className="font-black text-[10px] uppercase tracking-[0.2em] text-white">Entry Pengeluaran</span></div>
                                <form onSubmit={submitExpense} className="p-8 space-y-6">
                                    <div><label className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest block mb-2 leading-none">Kategori Beban</label><select value={data.category} onChange={e => setData('category', e.target.value)} className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm font-black dark:text-white focus:ring-cosmic-main uppercase cursor-pointer"><option value="Operasional">Operasional</option><option value="Beban Promosi">Beban Promosi (Promo/Gift)</option><option value="Pelunasan Hutang">Pelunasan Hutang</option><option value="Kerugian Stok">Kerugian Stok</option></select></div>
                                    <div><label className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest block mb-2 leading-none">Keterangan</label><input type="text" value={data.name} onChange={e => setData('name', e.target.value)} className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm font-bold dark:text-white focus:ring-cosmic-main" placeholder="Contoh: Belanja Gula / Bayar Listrik" /></div>
                                    <div><label className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest block mb-2 leading-none">Nominal Rp</label><input type="number" value={data.amount} onChange={e => setData('amount', e.target.value)} className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm font-black dark:text-white focus:ring-cosmic-main" placeholder="0" /></div>
                                    <button disabled={processing} className="w-full bg-cosmic text-white font-black text-[10px] uppercase py-5 rounded-2xl shadow-xl shadow-cosmic-main/20 tracking-[0.2em] active:scale-95 transition-all leading-none tracking-widest">POST TRANSACTION</button>
                                </form>
                            </div>
                        </div>

                        <div className="lg:col-span-2">
                            <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                                <div className="overflow-x-auto"><table className="w-full text-left text-xs"><thead className="bg-slate-50 dark:bg-slate-800/80 text-[9px] font-black uppercase text-slate-400 dark:text-slate-500 border-b border-slate-100 dark:border-slate-800 tracking-widest"><tr><th className="p-6 px-8">Deskripsi & Staff</th><th className="text-center">Kategori</th><th className="text-right px-8">Nominal</th><th className="text-center px-6">Nota</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800 font-bold">{report.expenseList.length > 0 ? report.expenseList.map((exp, i) => (<tr key={i} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-all group"><td className="p-5 px-8"><span className="uppercase text-slate-800 dark:text-slate-200 mb-1 block group-hover:text-cosmic-main transition-colors leading-none">{exp.name}</span><div className="flex items-center gap-3 text-[9px] text-slate-400 dark:text-slate-500 uppercase tracking-tight font-black italic"><span><IconClock size={12} className="inline mr-1"/> {new Date(exp.created_at).toLocaleTimeString('id-ID')}</span><span className="opacity-30">|</span><span className="text-cosmic-main uppercase">{exp.user?.name || 'SYSTEM AUTOMATION'}</span></div></td><td className="p-5 text-center"><span className={`px-3 py-1 rounded-full text-[8px] font-black uppercase shadow-sm border ${exp.category === 'Beban Promosi' ? 'bg-emerald-50 text-emerald-600 border-emerald-100 dark:bg-emerald-900/30' : exp.category === 'Kerugian Stok' ? 'bg-orange-50 text-orange-600 border-orange-100 dark:bg-orange-900/30' : 'bg-slate-50 text-slate-600 border-slate-200 dark:bg-slate-800/80'}`}>{exp.category}</span></td><td className={`p-5 text-right px-8 font-black text-sm tracking-tighter ${exp.category === 'Beban Promosi' ? 'text-emerald-500' : 'text-rose-500'}`}>-{formatCurrency(exp.amount)}</td><td className="p-5 text-center">{exp.image_url ? (<a href={exp.image_url} target="_blank" className="p-2.5 bg-slate-100 dark:bg-slate-800 hover:bg-cosmic-main hover:text-white rounded-xl inline-block transition-all shadow-sm"><IconPhoto size={18} /></a>) : <span className="opacity-20 italic font-black text-[9px]">NO ATTACH</span>}</td></tr>)) : <tr><td colSpan="4" className="p-20 text-center opacity-30 uppercase text-[10px] font-black italic dark:text-white tracking-[0.3em]">No Financial Records Found</td></tr>}</tbody></table></div></div>
                        </div>
                    </div>
                )}
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 20px; }
                .bg-cosmic { background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%); }
                .text-cosmic-main { color: #6366f1; }
                .border-cosmic-main { border-color: #6366f1; }
                .scrollbar-hide::-webkit-scrollbar { display: none; }
            ` }} />
        </>
    );
};

FinanceReport.layout = (page) => <DashboardLayout children={page} />;
export default FinanceReport;