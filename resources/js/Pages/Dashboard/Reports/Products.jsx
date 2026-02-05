import React, { useState } from "react";
import Layout from "@/Layouts/DashboardLayout";
import { Head, router } from "@inertiajs/react";
import Pagination from "@/Components/Dashboard/Pagination";
import { 
    IconSearch, IconArrowUpRight, IconAlertTriangle, 
    IconPackage, IconPrinter, IconCalendar
} from "@tabler/icons-react";

export default function Products({ products, categories, filters }) {
    // State untuk filter
    const [search, setSearch] = useState(filters.search || "");
    const [category, setCategory] = useState(filters.category_id || "");
    const [startDate, setStartDate] = useState(filters.start_date || "");
    const [endDate, setEndDate] = useState(filters.end_date || "");

    const handleFilter = (e) => {
        e.preventDefault();
        router.get(route('reports.products.index'), { 
            search, 
            category_id: category,
            start_date: startDate,
            end_date: endDate
        }, { preserveState: true });
    };

    const handleExport = () => {
        window.open(route('reports.products.export', { 
            search, 
            category_id: category,
            start_date: startDate,
            end_date: endDate
        }), '_blank');
    };

    const formatPrice = (price) => 
        new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(price || 0);

    return (
        <Layout>
            <Head title="Laporan Produk" />
            <div className="p-6 space-y-6">
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white uppercase tracking-tighter flex items-center gap-2">
                            <IconPackage className="text-primary-500" size={32} /> Laporan Produk
                        </h1>
                        <p className="text-sm text-slate-500 mt-1">Monitor stok dan akumulasi penjualan produk berdasarkan periode.</p>
                    </div>
                    <button onClick={handleExport} className="flex items-center gap-2 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-all shadow-lg shadow-emerald-500/20">
                        <IconPrinter size={18} /> CETAK PDF
                    </button>
                </div>

                {/* Filter Section */}
                <div className="bg-white dark:bg-slate-900 p-6 rounded-[2rem] shadow-sm border border-slate-200 dark:border-slate-800 flex flex-wrap gap-4 items-end transition-all">
                    {/* Search */}
                    <div className="flex-1 min-w-[200px]">
                        <label className="text-[10px] font-black uppercase text-slate-400 mb-2 block tracking-widest ml-1">Cari Nama / Barcode</label>
                        <div className="relative">
                            <IconSearch className="absolute left-4 top-3 text-slate-400" size={18} />
                            <input type="text" className="w-full pl-12 pr-4 py-2.5 rounded-2xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white text-sm focus:ring-primary-500" placeholder="Ketik pencarian..." value={search} onChange={e => setSearch(e.target.value)} />
                        </div>
                    </div>

                    {/* Start Date */}
                    <div className="w-full md:w-44">
                        <label className="text-[10px] font-black uppercase text-slate-400 mb-2 block tracking-widest ml-1">Dari Tanggal</label>
                        <div className="relative">
                            <input type="date" className="w-full px-4 py-2.5 rounded-2xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white text-sm focus:ring-primary-500" value={startDate} onChange={e => setStartDate(e.target.value)} />
                        </div>
                    </div>

                    {/* End Date */}
                    <div className="w-full md:w-44">
                        <label className="text-[10px] font-black uppercase text-slate-400 mb-2 block tracking-widest ml-1">Sampai Tanggal</label>
                        <div className="relative">
                            <input type="date" className="w-full px-4 py-2.5 rounded-2xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white text-sm focus:ring-primary-500" value={endDate} onChange={e => setEndDate(e.target.value)} />
                        </div>
                    </div>

                    {/* Category */}
                    <div className="w-full md:w-44">
                        <label className="text-[10px] font-black uppercase text-slate-400 mb-2 block tracking-widest ml-1">Kategori</label>
                        <select className="w-full border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white rounded-2xl text-sm py-2.5 px-4 focus:ring-primary-500 cursor-pointer" value={category} onChange={e => setCategory(e.target.value)}>
                            <option value="">Semua Kategori</option>
                            {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>

                    <button onClick={handleFilter} className="bg-slate-900 dark:bg-primary-500 text-white px-8 py-2.5 rounded-2xl font-black text-sm uppercase shadow-lg transition-all active:scale-95 flex items-center gap-2">
                        Terapkan
                    </button>
                </div>

                <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                    <table className="w-full text-left">
                        <thead className="bg-slate-50/50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th className="p-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Produk</th>
                                <th className="p-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Kategori</th>
                                <th className="p-5 text-center text-[10px] font-black uppercase text-slate-400 tracking-widest">Stok Sisa</th>
                                <th className="p-5 text-center text-[10px] font-black uppercase text-slate-400 tracking-widest">Qty Terjual</th>
                                <th className="p-5 text-right text-[10px] font-black uppercase text-slate-400 tracking-widest">Omzet Periode</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {products.data.map((p) => (
                                <tr key={p.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                    <td className="p-5">
                                        <div className="font-bold text-slate-800 dark:text-white text-xs uppercase">{p.title}</div>
                                        <div className="text-[10px] text-slate-400 mt-1 font-mono">{p.barcode}</div>
                                    </td>
                                    <td className="p-5">
                                        <span className="text-[10px] bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded-lg font-black text-slate-500 uppercase tracking-tighter border dark:border-slate-700">
                                            {p.category?.name || 'Umum'}
                                        </span>
                                    </td>
                                    <td className="p-5 text-center">
                                        <div className="flex items-center justify-center gap-2 font-black text-sm">
                                            <span className={p.stock <= 5 ? 'text-rose-500' : 'text-slate-700 dark:text-slate-300'}>{p.stock}</span>
                                            {p.stock <= 5 && <IconAlertTriangle size={18} className="text-rose-500 animate-pulse" />}
                                        </div>
                                    </td>
                                    <td className="p-5 text-center">
                                        <div className="inline-flex items-center gap-1 font-black text-emerald-600 text-sm bg-emerald-50 dark:bg-emerald-900/20 px-3 py-1 rounded-full">
                                            {p.total_sold || 0} <IconArrowUpRight size={14} />
                                        </div>
                                    </td>
                                    <td className="p-5 text-right font-black text-slate-900 dark:text-white text-sm italic">
                                        {formatPrice(p.total_revenue || 0)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="flex justify-center pt-4">
                    <Pagination links={products.links} />
                </div>
            </div>
        </Layout>
    );
}