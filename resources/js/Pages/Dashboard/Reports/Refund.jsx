import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Dashboard/Pagination";
import {
    IconRefresh,
    IconCalendar,
    IconSearch,
    IconFileDescription,
    IconUser,
    IconHash,
    IconTrendingDown,
    IconAlertCircle
} from "@tabler/icons-react";

const formatCurrency = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const RefundReport = ({ refunds, totalRefund, filters }) => {
    // 1. STATE UNTUK FILTER (Menjaga sinkronisasi dengan backend)
    const [startDate, setStartDate] = useState(filters.start_date || "");
    const [endDate, setEndDate] = useState(filters.end_date || "");
    const [invoice, setInvoice] = useState(filters.invoice || "");

    const handleFilter = (e) => {
        e.preventDefault();
        router.get(
            route("reports.refund"),
            { 
                start_date: startDate, 
                end_date: endDate,
                invoice: invoice 
            },
            { preserveState: true, replace: true }
        );
    };

    const resetFilter = () => {
        setStartDate("");
        setEndDate("");
        setInvoice("");
        router.get(route("reports.refund"), {}, { replace: true });
    };

    return (
        <>
            <Head title="Laporan Refund | POS SYSTEM AJA" />
            <div className="space-y-6">
                
                {/* 1. Header & Filter Section */}
                <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white flex items-center gap-3 uppercase tracking-tighter italic">
                            <div className="p-2 bg-indigo-600 rounded-xl text-white shadow-lg shadow-indigo-500/20">
                                <IconRefresh size={24} />
                            </div>
                            Laporan Refund
                        </h1>
                        <p className="text-slate-500 dark:text-slate-400 text-sm mt-1 font-medium">
                            Monitoring pembatalan transaksi dan pemulihan stok inventori.
                        </p>
                    </div>

                    {/* Filter Bar Modern */}
                    <form onSubmit={handleFilter} className="flex flex-wrap items-center gap-3 bg-white dark:bg-slate-900 p-3 rounded-[1.5rem] border border-slate-200 dark:border-slate-800 shadow-sm">
                        {/* Input Search Invoice */}
                        <div className="flex items-center gap-2 px-3 py-2 bg-slate-50 dark:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-700 focus-within:ring-2 focus-within:ring-indigo-500/20 transition-all">
                            <IconHash size={18} className="text-slate-400" />
                            <input
                                type="text"
                                placeholder="Cari Invoice..."
                                className="border-none bg-transparent text-sm focus:ring-0 p-0 text-slate-700 dark:text-slate-300 w-32 font-bold uppercase"
                                value={invoice}
                                onChange={(e) => setInvoice(e.target.value)}
                            />
                        </div>

                        {/* Input Date Range */}
                        <div className="flex items-center gap-3 px-3 py-2 bg-slate-50 dark:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-700">
                            <IconCalendar size={18} className="text-slate-400" />
                            <input
                                type="date"
                                className="border-none bg-transparent text-sm focus:ring-0 p-0 text-slate-700 dark:text-slate-300 cursor-pointer font-bold"
                                value={startDate}
                                onChange={(e) => setStartDate(e.target.value)}
                            />
                            <span className="text-slate-300 dark:text-slate-600">/</span>
                            <input
                                type="date"
                                className="border-none bg-transparent text-sm focus:ring-0 p-0 text-slate-700 dark:text-slate-300 cursor-pointer font-bold"
                                value={endDate}
                                onChange={(e) => setEndDate(e.target.value)}
                            />
                        </div>

                        <div className="flex gap-2">
                            <button
                                type="submit"
                                className="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl transition-all shadow-lg shadow-indigo-500/20 flex items-center gap-2 font-black text-xs uppercase tracking-widest active:scale-95"
                            >
                                <IconSearch size={16} />
                                Cari
                            </button>
                            {(startDate || endDate || invoice) && (
                                <button
                                    type="button"
                                    onClick={resetFilter}
                                    className="bg-slate-100 dark:bg-slate-800 text-slate-500 p-2.5 rounded-xl hover:bg-red-50 hover:text-red-500 transition-all"
                                >
                                    <IconRefresh size={18} />
                                </button>
                            )}
                        </div>
                    </form>
                </div>

                {/* 2. Kartu Ringkasan Total (Hero Section) */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div className="bg-gradient-to-br from-rose-500 to-rose-600 p-7 rounded-[2.5rem] text-white shadow-xl shadow-rose-500/20 relative overflow-hidden group">
                        <div className="absolute -right-4 -top-4 opacity-10 group-hover:scale-110 transition-transform duration-700">
                            <IconTrendingDown size={140} />
                        </div>
                        <div className="relative z-10">
                            <p className="text-rose-100 text-[10px] font-black uppercase tracking-[0.2em]">Total Nilai Pengembalian</p>
                            <h3 className="text-4xl font-black mt-2 tracking-tighter">
                                {formatCurrency(totalRefund)}
                            </h3>
                            <div className="mt-5 inline-flex items-center gap-2 text-[10px] bg-white/20 px-4 py-1.5 rounded-full backdrop-blur-md font-black uppercase tracking-widest border border-white/10">
                                <IconRefresh size={12} className="animate-spin-slow" /> {refunds.total} Transaksi Dibatalkan
                            </div>
                        </div>
                    </div>

                    <div className="md:col-span-2 bg-indigo-50 dark:bg-indigo-900/10 border border-indigo-100 dark:border-indigo-800/50 rounded-[2.5rem] p-7 flex items-center gap-6">
                        <div className="w-16 h-16 rounded-3xl bg-white dark:bg-slate-800 flex items-center justify-center text-indigo-600 shadow-sm border border-indigo-100 dark:border-indigo-800">
                            <IconAlertCircle size={32} />
                        </div>
                        <div>
                            <h4 className="font-black text-indigo-900 dark:text-indigo-300 uppercase text-xs tracking-widest">Informasi Audit</h4>
                            <p className="text-slate-500 dark:text-slate-400 text-sm mt-1 leading-relaxed max-w-md">
                                Data ini mencakup semua dana yang dikembalikan ke pelanggan. Pastikan fisik barang telah kembali ke rak inventori.
                            </p>
                        </div>
                    </div>
                </div>

                {/* 3. Tabel Data Modern */}
                <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left border-collapse">
                            <thead>
                                <tr className="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 uppercase font-black text-[10px] tracking-[0.15em] border-b border-slate-100 dark:border-slate-800">
                                    <th className="px-8 py-6">Waktu Refund</th>
                                    <th className="px-8 py-6">Identitas Invoice</th>
                                    <th className="px-8 py-6">Otoritas (Admin/Kasir)</th>
                                    <th className="px-8 py-6 text-right">Nominal Refund</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50 dark:divide-slate-800 font-medium">
                                {refunds.data.length > 0 ? (
                                    refunds.data.map((item) => (
                                        <tr key={item.id} className="group hover:bg-indigo-50/30 dark:hover:bg-indigo-900/10 transition-colors">
                                            <td className="px-8 py-6 text-slate-600 dark:text-slate-300">
                                                <div className="font-bold text-slate-900 dark:text-white uppercase tracking-tight">
                                                    {new Date(item.updated_at).toLocaleDateString("id-ID", {
                                                        day: "numeric", month: "long", year: "numeric"
                                                    })}
                                                </div>
                                                <div className="text-[10px] font-black text-slate-400 uppercase mt-1 flex items-center gap-1.5">
                                                    <div className="w-1.5 h-1.5 rounded-full bg-slate-300"></div>
                                                    Pukul {new Date(item.updated_at).toLocaleTimeString("id-ID", {
                                                        hour: "2-digit", minute:"2-digit"
                                                    })} WIB
                                                </div>
                                            </td>
                                            <td className="px-8 py-6">
                                                <div className="inline-flex px-4 py-1.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-xl font-black text-xs group-hover:bg-indigo-600 group-hover:text-white group-hover:shadow-lg group-hover:shadow-indigo-500/30 transition-all border border-slate-200 dark:border-slate-700 uppercase tracking-widest">
                                                    {item.invoice}
                                                </div>
                                            </td>
                                            <td className="px-8 py-6 text-slate-600 dark:text-slate-400">
                                                <div className="flex items-center gap-3">
                                                    <div className="w-9 h-9 rounded-2xl bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 border border-indigo-200 dark:border-indigo-800">
                                                        <IconUser size={18} />
                                                    </div>
                                                    <div className="flex flex-col">
                                                        <span className="font-black text-xs uppercase tracking-tight text-slate-700 dark:text-slate-200">{item.cashier?.name || 'Authorized Admin'}</span>
                                                        <span className="text-[9px] font-bold text-slate-400 uppercase">Super Admin Role</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-8 py-6 text-right">
                                                <span className="text-xl font-black text-rose-600 dark:text-rose-400 tracking-tighter drop-shadow-sm">
                                                    {formatCurrency(item.grand_total)}
                                                </span>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="4" className="px-8 py-24 text-center">
                                            <div className="flex flex-col items-center justify-center opacity-40 grayscale">
                                                <div className="w-20 h-20 bg-slate-100 dark:bg-slate-800 rounded-[2rem] flex items-center justify-center mb-5">
                                                    <IconRefresh size={48} className="text-slate-400 animate-spin-slow" />
                                                </div>
                                                <p className="text-xl font-black text-slate-500 dark:text-slate-400 uppercase tracking-[0.2em]">Zero Data Found</p>
                                                <p className="text-sm text-slate-400 font-medium mt-2 italic">Belum ada riwayat pembatalan transaksi yang tersimpan.</p>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* 4. Pagination Section */}
                <div className="flex justify-center mt-10 pb-10">
                    {refunds.links && <Pagination links={refunds.links} />}
                </div>
            </div>

            {/* Custom Styles for Animations */}
            <style>{`
                .animate-spin-slow { animation: spin 12s linear infinite; }
                @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
                tr:last-child td:first-child { border-bottom-left-radius: 2.5rem; }
                tr:last-child td:last-child { border-bottom-right-radius: 2.5rem; }
            `}</style>
        </>
    );
};

RefundReport.layout = (page) => <DashboardLayout children={page} />;

export default RefundReport;