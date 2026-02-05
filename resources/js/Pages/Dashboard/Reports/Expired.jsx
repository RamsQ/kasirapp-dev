import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router } from "@inertiajs/react";
import { 
    IconAlertTriangle, 
    IconCalendarTime, 
    IconPackageOff, 
    IconCheckupList,
    IconClockStop,
    IconSearch,
    IconFileTypePdf,
    IconFileTypeXls,
    IconFilter,
    IconTrash
} from "@tabler/icons-react";
import Swal from "sweetalert2";

export default function Expired({ products, filter }) {
    // State untuk filter tanggal
    const [startDate, setStartDate] = useState(filter.start_date);
    const [endDate, setEndDate] = useState(filter.end_date);

    // Fungsi untuk menjalankan filter
    const handleFilter = (e) => {
        e.preventDefault();
        router.get(route('reports.expired.index'), { 
            start_date: startDate, 
            end_date: endDate 
        }, { preserveState: true });
    };

    // [FITUR BARU] Fungsi untuk menghapus stok expired & catat kerugian ke keuangan
    const handleDestroyStock = (id, title) => {
        Swal.fire({
            title: 'Hapus Stok Expired?',
            text: `Seluruh stok ${title} akan dinolkan dan dicatat sebagai beban kerugian di laporan keuangan.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Bersihkan Stok!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route('reports.expired.destroy_stock', id), {
                    onSuccess: () => Swal.fire('Berhasil', 'Stok dibersihkan dan kerugian dicatat.', 'success'),
                });
            }
        });
    };

    return (
        <DashboardLayout>
            <Head title="Laporan Produk Expired" />
            
            {/* Header Section */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div className="flex items-center gap-3">
                    <div className="p-3 bg-red-500 rounded-2xl text-white shadow-lg shadow-red-200 dark:shadow-none">
                        <IconAlertTriangle size={24} />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white uppercase tracking-tight">
                            Kontrol Produk Expired
                        </h1>
                        <p className="text-sm text-slate-500 font-medium uppercase tracking-wider flex items-center gap-2">
                            <IconClockStop size={14} /> Monitoring & Pembersihan Stok Kadaluarsa
                        </p>
                    </div>
                </div>

                {/* Export Buttons */}
                <div className="flex items-center gap-2">
                    <a 
                        href={route('reports.expired.pdf', { start_date: startDate, end_date: endDate })}
                        className="bg-red-500 hover:bg-red-600 text-white px-4 py-2.5 rounded-xl text-xs font-bold flex items-center gap-2 transition-all shadow-lg shadow-red-200 dark:shadow-none"
                    >
                        <IconFileTypePdf size={18} /> Export PDF
                    </a>
                    <a 
                        href={route('reports.expired.excel', { start_date: startDate, end_date: endDate })}
                        className="bg-green-600 hover:bg-green-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold flex items-center gap-2 transition-all shadow-lg shadow-green-200 dark:shadow-none"
                    >
                        <IconFileTypeXls size={18} /> Export Excel
                    </a>
                </div>
            </div>

            {/* Filter Section */}
            <div className="bg-white dark:bg-slate-900 p-6 rounded-[2rem] border border-slate-200 dark:border-slate-800 mb-6 shadow-sm">
                <form onSubmit={handleFilter} className="flex flex-wrap items-end gap-4">
                    <div className="flex-1 min-w-[200px]">
                        <label className="text-[10px] font-black uppercase text-slate-400 mb-2 block tracking-widest flex items-center gap-1">
                            <IconFilter size={14} /> Dari Tanggal
                        </label>
                        <input 
                            type="date" 
                            value={startDate} 
                            onChange={(e) => setStartDate(e.target.value)} 
                            className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm focus:ring-primary-500 focus:border-primary-500 transition-all" 
                        />
                    </div>
                    <div className="flex-1 min-w-[200px]">
                        <label className="text-[10px] font-black uppercase text-slate-400 mb-2 block tracking-widest flex items-center gap-1">
                            <IconFilter size={14} /> Sampai Tanggal
                        </label>
                        <input 
                            type="date" 
                            value={endDate} 
                            onChange={(e) => setEndDate(e.target.value)} 
                            className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm focus:ring-primary-500 focus:border-primary-500 transition-all" 
                        />
                    </div>
                    <button 
                        type="submit" 
                        className="bg-slate-900 dark:bg-primary-500 hover:opacity-90 text-white px-8 py-3 rounded-xl font-bold text-xs uppercase flex items-center gap-2 transition-all active:scale-95 shadow-lg shadow-slate-200 dark:shadow-none"
                    >
                        <IconSearch size={18} /> Tampilkan Laporan
                    </button>
                </form>
            </div>

            {/* Table Section */}
            <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm text-left">
                        <thead className="text-[10px] text-slate-500 uppercase bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800 font-black tracking-widest">
                            <tr>
                                <th className="px-8 py-5">Produk</th>
                                <th className="px-6 py-5 text-center">Stok Tersisa</th>
                                <th className="px-6 py-5">Tanggal Expired</th>
                                <th className="px-8 py-5">Status & Sisa Waktu</th>
                                <th className="px-8 py-5 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {products.length > 0 ? products.map((product) => {
                                const days = product.days_until_expired;
                                const isExpired = days <= 0;
                                
                                return (
                                    <tr key={product.id} className={`transition-colors ${isExpired ? 'bg-red-50/30 dark:bg-red-900/5' : 'hover:bg-slate-50 dark:hover:bg-slate-800/50'}`}>
                                        <td className="px-8 py-5">
                                            <div className="flex items-center gap-4">
                                                <div className="w-12 h-12 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 shadow-sm">
                                                    <img 
                                                        src={product.image ? product.image : `https://ui-avatars.com/api/?name=${encodeURIComponent(product.title)}&background=random`} 
                                                        className="w-full h-full object-cover" 
                                                        alt={product.title}
                                                        onError={(e) => {
                                                            e.target.src = `https://ui-avatars.com/api/?name=${encodeURIComponent(product.title)}&background=random`;
                                                        }}
                                                    />
                                                </div>
                                                <div>
                                                    <div className="font-bold text-slate-900 dark:text-white uppercase leading-tight">{product.title}</div>
                                                    <div className="text-[10px] text-slate-400 font-mono mt-0.5 tracking-tighter">SKU: {product.barcode || '-'}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-6 py-5 text-center">
                                            <div className="inline-flex flex-col">
                                                <span className="text-sm font-black text-slate-700 dark:text-slate-300">{product.stock}</span>
                                                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">{product.unit || 'Pcs'}</span>
                                            </div>
                                        </td>
                                        <td className="px-6 py-5">
                                            <div className="flex flex-col">
                                                <span className={`font-bold text-sm ${isExpired ? 'text-red-600' : 'text-slate-700 dark:text-slate-300'}`}>
                                                    {new Date(product.expired_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' })}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-8 py-5">
                                            {isExpired ? (
                                                <div className="flex items-center gap-2 text-red-600 bg-red-100 dark:bg-red-900/30 px-4 py-2 rounded-2xl w-fit border border-red-200 dark:border-red-800">
                                                    <IconPackageOff size={18} strokeWidth={2.5} />
                                                    <span className="text-xs font-black uppercase italic">Kadaluarsa!</span>
                                                </div>
                                            ) : (
                                                <div className="space-y-2">
                                                    <div className="flex items-center justify-between gap-4">
                                                        <div className="flex items-center gap-2 text-amber-600 bg-amber-50 dark:bg-amber-900/20 px-3 py-1 rounded-full w-fit border border-amber-100 dark:border-amber-800">
                                                            <IconCalendarTime size={16} />
                                                            <span className="text-[11px] font-black uppercase whitespace-nowrap">Sisa {days} Hari</span>
                                                        </div>
                                                    </div>
                                                    <div className="w-40 bg-slate-100 dark:bg-slate-800 rounded-full h-1.5 overflow-hidden">
                                                        <div 
                                                            className={`h-full transition-all duration-500 rounded-full ${days <= 7 ? 'bg-red-500' : 'bg-amber-500'}`} 
                                                            style={{ width: `${Math.min(100, (days / 30) * 100)}%` }}
                                                        ></div>
                                                    </div>
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-8 py-5 text-center">
                                            {isExpired && product.stock > 0 && (
                                                <button 
                                                    onClick={() => handleDestroyStock(product.id, product.title)}
                                                    className="p-2.5 bg-red-50 hover:bg-red-500 text-red-500 hover:text-white rounded-xl transition-all border border-red-100 hover:border-red-500 group shadow-sm active:scale-95"
                                                    title="Bersihkan Stok"
                                                >
                                                    <IconTrash size={18} />
                                                </button>
                                            )}
                                            {product.stock <= 0 && (
                                                <span className="text-[10px] font-bold text-slate-300 uppercase italic">Sudah Dibersihkan</span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            }) : (
                                <tr>
                                    <td colSpan="5" className="py-24 text-center">
                                        <div className="flex flex-col items-center opacity-20">
                                            <IconCheckupList size={64} className="text-slate-400" />
                                            <p className="mt-4 text-sm font-black uppercase tracking-[0.3em] text-slate-500">Data Tidak Ditemukan</p>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </DashboardLayout>
    );
}