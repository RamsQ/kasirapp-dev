import React, { useState, useEffect } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { 
    ResponsiveContainer, AreaChart, Area, XAxis, YAxis, 
    CartesianGrid, Tooltip, Legend 
} from "recharts";
import {
    IconCash, IconPlus, IconCalendar, IconSearch,
    IconTrendingUp, IconReceipt2, IconTable,
    IconReceipt, IconUser, IconClock, IconChartBar, 
    IconPhoto, IconLayoutDashboard, IconWallet, IconArrowUpRight, IconPackage,
    IconAlertTriangle, IconFilter, IconArrowDownRight, IconGift, IconWorld
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

    // --- LOGIKA FILTER RINCIAN BEBAN ---
    // 1. Beban Komisi Aplikasi (Markup + Flat Fee dikirim dari Controller summary)
    const appCommissionTotal = report.summary?.app_expenses || 0;

    // 2. Kerugian Stok (Expired / Opname)
    const inventoryLossTotal = report.expenseList
        ?.filter(exp => exp.category === 'Kerugian Stok')
        .reduce((acc, curr) => acc + parseFloat(curr.amount), 0) || 0;

    // 3. Beban Promosi (Produk Gratis dari Buy X Get Y)
    const promotionExpenseTotal = report.expenseList
        ?.filter(exp => exp.category === 'Beban Promosi' || exp.name?.includes('PROMO:'))
        .reduce((acc, curr) => acc + parseFloat(curr.amount), 0) || 0;

    // 4. Biaya Operasional Murni
    // Kita kurangi total report.expenses dengan beban yang sudah dikategorikan khusus
    const generalOperationalTotal = report.expenses - inventoryLossTotal - promotionExpenseTotal - appCommissionTotal;

    return (
        <>
            <Head title="Laporan Keuangan" />
            <div className="space-y-6 text-slate-900 dark:text-slate-100">
                
                {/* 1. Header & Tab Navigation */}
                <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 border-b border-slate-200 dark:border-slate-800 pb-2">
                    <div>
                        <h1 className="text-2xl font-black flex items-center gap-3 uppercase tracking-tight">
                            <div className="p-2 bg-cosmic rounded-xl text-white shadow-lg">
                                <IconWallet size={24} />
                            </div>
                            Finance <span className="text-cosmic-main italic">Hub</span>
                        </h1>
                        <div className="flex gap-8 mt-6">
                            {['laba-rugi', 'neraca', 'pengeluaran'].map((tab) => (
                                <button 
                                    key={tab}
                                    onClick={() => setActiveTab(tab)} 
                                    className={`pb-3 text-[10px] font-black uppercase tracking-[0.2em] transition-all border-b-4 ${
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
                                <span className="text-slate-300 dark:text-slate-700 font-bold">TO</span>
                                <input type="date" className="border-none bg-transparent text-[10px] font-black focus:ring-0 p-1 dark:text-white uppercase" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
                            </div>
                            <div className="h-4 w-[1px] bg-slate-200 dark:bg-slate-700"></div>
                            <select value={filterStaff} onChange={(e) => setFilterStaff(e.target.value)} className="border-none bg-transparent text-[10px] font-black focus:ring-0 p-1 uppercase dark:text-white cursor-pointer">
                                <option value="" className="dark:bg-slate-900">Semua Staff</option>
                                {report.staffList?.map(s => <option key={s.id} value={s.id} className="dark:bg-slate-900">{s.name}</option>)}
                            </select>
                            <button type="submit" className="bg-cosmic text-white p-2 rounded-xl transition-all active:scale-95 shadow-md shadow-cosmic-main/20">
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
                                <div className="p-4 bg-blue-500 rounded-2xl text-white shadow-lg group-hover:scale-110 transition-transform"><IconReceipt size={28}/></div>
                                <div>
                                    <p className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest">Omzet Bruto</p>
                                    <h3 className="text-xl font-black text-blue-600 dark:text-blue-400 tracking-tighter">{formatCurrency(report.revenue)}</h3>
                                </div>
                            </div>
                            <div className="bg-white dark:bg-slate-900 p-6 rounded-[2rem] border border-slate-200 dark:border-slate-800 flex items-center gap-5 shadow-sm group">
                                <div className="p-4 bg-orange-500 rounded-2xl text-white shadow-lg group-hover:scale-110 transition-transform"><IconWorld size={28}/></div>
                                <div>
                                    <p className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest">Beban App</p>
                                    <h3 className="text-xl font-black text-orange-600 dark:text-orange-400 tracking-tighter">{formatCurrency(appCommissionTotal)}</h3>
                                </div>
                            </div>
                            <div className="bg-white dark:bg-slate-900 p-6 rounded-[2rem] border border-slate-200 dark:border-slate-800 flex items-center gap-5 shadow-sm group">
                                <div className="p-4 bg-rose-500 rounded-2xl text-white shadow-lg group-hover:scale-110 transition-transform"><IconReceipt2 size={28}/></div>
                                <div>
                                    <p className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest">Total Beban</p>
                                    <h3 className="text-xl font-black text-rose-600 dark:text-rose-400 tracking-tighter">{formatCurrency(report.expenses)}</h3>
                                </div>
                            </div>
                            <div className="bg-cosmic p-6 rounded-[2rem] flex items-center gap-5 text-white shadow-xl shadow-cosmic-main/20 group relative overflow-hidden">
                                <div className="p-4 bg-white/20 rounded-2xl z-10 group-hover:rotate-12 transition-transform"><IconCash size={28} /></div>
                                <div className="z-10">
                                    <p className="text-[10px] font-black text-white/80 uppercase tracking-widest">Laba Bersih</p>
                                    <h3 className="text-2xl font-black tracking-tighter italic">{formatCurrency(report.netProfit)}</h3>
                                </div>
                                <IconArrowUpRight size={100} className="absolute -bottom-4 -right-4 opacity-10" />
                            </div>
                        </div>

                        {/* Statement Table */}
                        <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-10 shadow-sm relative overflow-hidden">
                            <h3 className="font-black text-[10px] uppercase tracking-[0.2em] text-slate-400 mb-8 italic">Financial Statement Analysis</h3>
                            
                            <div className="space-y-5 text-sm font-bold relative z-10">
                                <div className="flex justify-between text-slate-500 dark:text-slate-400 uppercase tracking-tighter">
                                    <span>Total Penjualan Kotor (Gross Revenue)</span>
                                    <span>{formatCurrency(report.revenue)}</span>
                                </div>

                                <div className="flex justify-between text-rose-500 dark:text-rose-400 uppercase tracking-tighter">
                                    <span>Total HPP (Modal Barang Terjual)</span>
                                    <span>({formatCurrency(report.hpp)})</span>
                                </div>

                                {/* AKUN BARU: BEBAN KOMISI APLIKASI */}
                                <div className="flex justify-between items-center py-3 px-5 bg-orange-50/40 dark:bg-orange-950/10 border border-dashed border-orange-200 dark:border-orange-900/40 rounded-2xl">
                                    <div className="flex items-center gap-3">
                                        <IconWorld className="text-orange-500" size={20} />
                                        <div>
                                            <span className="text-orange-600 dark:text-orange-400 uppercase tracking-tighter block leading-none mb-1">Beban Komisi Aplikasi Online</span>
                                            <span className="text-[8px] text-orange-400 font-black uppercase">Markup & Fee Layanan Pihak Ketiga</span>
                                        </div>
                                    </div>
                                    <span className="text-orange-600 font-black tracking-tighter">({formatCurrency(appCommissionTotal)})</span>
                                </div>

                                <div className="pt-5 border-t border-slate-100 dark:border-slate-800 flex justify-between font-black text-blue-600 dark:text-blue-400 text-2xl tracking-tighter">
                                    <span>LABA KOTOR (GROSS PROFIT)</span>
                                    <span>{formatCurrency(report.grossProfit)}</span>
                                </div>
                                
                                {/* Detail Biaya / Beban */}
                                <div className="space-y-3 pl-4 border-l-2 border-slate-100 dark:border-slate-800 mt-4">
                                    <div className="flex justify-between text-slate-400 italic text-xs font-medium">
                                        <span>Beban Operasional Umum (Listrik, Gaji, dll)</span>
                                        <span>({formatCurrency(generalOperationalTotal)})</span>
                                    </div>
                                    <div className="flex justify-between text-orange-500 italic text-xs font-black">
                                        <span className="flex items-center gap-1"><IconAlertTriangle size={12}/> Beban Penurunan Nilai Stok (Expired/Opname)</span>
                                        <span>({formatCurrency(inventoryLossTotal)})</span>
                                    </div>
                                    <div className="flex justify-between text-emerald-500 italic text-xs font-black">
                                        <span className="flex items-center gap-1"><IconGift size={12}/> Beban Promosi (Produk Gratis / Hadiah)</span>
                                        <span>({formatCurrency(promotionExpenseTotal)})</span>
                                    </div>
                                </div>

                                <div className="pt-6 border-t-4 border-double border-slate-200 dark:border-slate-800 flex justify-between font-black text-emerald-600 dark:text-emerald-400 text-3xl tracking-tighter italic">
                                    <span>LABA BERSIH (NET PROFIT)</span>
                                    <span>{formatCurrency(report.netProfit)}</span>
                                </div>
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
                                    <h4 className="flex items-center gap-3 text-[10px] font-black text-blue-600 dark:text-blue-400 uppercase tracking-[0.3em] border-b border-blue-50 dark:border-blue-900/30 pb-4">
                                        <IconChartBar size={18}/> Aktiva (Harta Toko)
                                    </h4>
                                    <div className="space-y-6 text-sm font-bold">
                                        {[
                                            { label: 'Kas di Laci (Tunai)', val: report.balanceSheet.cash_in_drawer, color: 'text-slate-600 dark:text-slate-300' },
                                            { label: 'Saldo Modal Luar (Bank)', val: report.balanceSheet.external_capital, color: 'text-emerald-600 dark:text-emerald-400' },
                                            { label: 'Persediaan Barang (Inventory)', val: report.balanceSheet.inventory_value, color: 'text-slate-600 dark:text-slate-300' },
                                        ].map((item, i) => (
                                            <div key={i} className="flex justify-between uppercase tracking-tighter">
                                                <span className="text-slate-400 dark:text-slate-500">{item.label}</span>
                                                <span className={item.color}>{formatCurrency(item.val)}</span>
                                            </div>
                                        ))}
                                        <div className="pt-8 border-t-4 border-blue-600 dark:border-blue-500 flex justify-between items-center text-blue-600 dark:text-blue-400 text-2xl font-black tracking-tighter">
                                            <span>TOTAL ASET</span>
                                            <span>{formatCurrency(report.balanceSheet.total_assets)}</span>
                                        </div>
                                    </div>
                                </div>
                                <div className="space-y-8">
                                    <h4 className="flex items-center gap-3 text-[10px] font-black text-emerald-600 dark:text-emerald-400 uppercase tracking-[0.3em] border-b border-emerald-50 dark:border-emerald-900/30 pb-4">
                                        <IconReceipt size={18}/> Pasiva (Modal & Kewajiban)
                                    </h4>
                                    <div className="space-y-6 text-sm font-bold">
                                        {[
                                            { label: 'Hutang Dagang (Payable)', val: report.balanceSheet.accounts_payable, color: 'text-rose-600 dark:text-rose-400' },
                                            { label: 'Modal Disetor (Equity)', val: report.balanceSheet.external_capital, color: 'text-slate-600 dark:text-slate-300' },
                                            { label: 'Laba Ditahan (Retained)', val: report.balanceSheet.retained_earnings, color: 'text-slate-600 dark:text-slate-300' },
                                        ].map((item, i) => (
                                            <div key={i} className="flex justify-between uppercase tracking-tighter">
                                                <span className="text-slate-400 dark:text-slate-500">{item.label}</span>
                                                <span className={item.color}>{formatCurrency(item.val)}</span>
                                            </div>
                                        ))}
                                        <div className="pt-8 border-t-4 border-emerald-600 dark:border-emerald-500 flex justify-between items-center text-emerald-600 dark:text-emerald-400 text-2xl font-black tracking-tighter">
                                            <span>TOTAL PASIVA</span>
                                            <span>{formatCurrency(report.balanceSheet.total_assets)}</span>
                                        </div>
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
                                <div className="p-6 bg-cosmic flex items-center gap-3 border-b border-white/10">
                                    <IconPlus size={20} className="text-white" /><span className="font-black text-[10px] uppercase tracking-[0.2em] text-white">Input Pengeluaran</span>
                                </div>
                                <form onSubmit={submitExpense} className="p-8 space-y-6">
                                    <div>
                                        <label className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest block mb-2">Kategori Beban</label>
                                        <select value={data.category} onChange={e => setData('category', e.target.value)} className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm font-black dark:text-white focus:ring-cosmic-main uppercase cursor-pointer">
                                            <option value="Operasional">Operasional</option>
                                            <option value="Beban Promosi">Beban Promosi (Promo/Gift)</option>
                                            <option value="Pelunasan Hutang">Pelunasan Hutang</option>
                                            <option value="Kerugian Stok">Kerugian Stok</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest block mb-2">Keterangan</label>
                                        <input type="text" value={data.name} onChange={e => setData('name', e.target.value)} className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm font-bold dark:text-white focus:ring-cosmic-main" placeholder="Misal: Biaya Kirim / Listrik" />
                                    </div>
                                    <div>
                                        <label className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-widest block mb-2">Nominal Rp</label>
                                        <input type="number" value={data.amount} onChange={e => setData('amount', e.target.value)} className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm font-black dark:text-white focus:ring-cosmic-main" placeholder="0" />
                                    </div>
                                    <button disabled={processing} className="w-full bg-cosmic text-white font-black text-[10px] uppercase py-5 rounded-2xl shadow-xl shadow-cosmic-main/20 tracking-[0.2em] active:scale-95 transition-all">Simpan Biaya</button>
                                </form>
                            </div>
                        </div>

                        <div className="lg:col-span-2">
                            <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-xs">
                                        <thead className="bg-slate-50 dark:bg-slate-800/80 text-[9px] font-black uppercase text-slate-400 dark:text-slate-500 border-b border-slate-100 dark:border-slate-800 tracking-widest">
                                            <tr>
                                                <th className="p-6 px-8">Transaksi & Petugas</th>
                                                <th className="text-center">Kategori</th>
                                                <th className="text-right px-8">Nominal</th>
                                                <th className="text-center px-6">Nota</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800 font-bold">
                                            {report.expenseList.length > 0 ? report.expenseList.map((exp, i) => (
                                                <tr key={i} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-all group">
                                                    <td className="p-5 px-8">
                                                        <span className="uppercase text-slate-800 dark:text-slate-200 mb-1 block group-hover:text-cosmic-main transition-colors">{exp.name}</span>
                                                        <div className="flex items-center gap-3 text-[9px] text-slate-400 dark:text-slate-500 uppercase tracking-tight">
                                                            <span className="flex items-center gap-1"><IconClock size={12} /> {new Date(exp.created_at).toLocaleTimeString('id-ID')}</span>
                                                            <span className="opacity-30">|</span>
                                                            <span className="flex items-center gap-1 text-cosmic-main"><IconUser size={12} /> {exp.user?.name || 'Otomatis Sistem'}</span>
                                                        </div>
                                                    </td>
                                                    <td className="p-5 text-center">
                                                        <span className={`px-3 py-1 rounded-full text-[8px] font-black uppercase shadow-sm ${
                                                            exp.category === 'Beban Promosi' ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30' :
                                                            exp.category === 'Kerugian Stok' ? 'bg-orange-100 text-orange-600 dark:bg-orange-900/30' :
                                                            'bg-slate-100 text-slate-600 dark:bg-slate-800/80'
                                                        }`}>{exp.category}</span>
                                                    </td>
                                                    <td className={`p-5 text-right px-8 font-black text-sm tracking-tighter ${exp.category === 'Beban Promosi' ? 'text-emerald-500' : 'text-rose-500'}`}>
                                                        -{formatCurrency(exp.amount)}
                                                    </td>
                                                    <td className="p-5 text-center">
                                                        {exp.image_url ? (
                                                            <a href={exp.image_url} target="_blank" className="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg inline-block transition-all">
                                                                <IconPhoto size={18} className="text-cosmic-main" />
                                                            </a>
                                                        ) : <span className="opacity-20">-</span>}
                                                    </td>
                                                </tr>
                                            )) : <tr><td colSpan="4" className="p-20 text-center opacity-30 uppercase text-[10px] font-black italic dark:text-white tracking-[0.3em]">No Financial Records Found</td></tr>}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
};

FinanceReport.layout = (page) => <DashboardLayout children={page} />;
export default FinanceReport;