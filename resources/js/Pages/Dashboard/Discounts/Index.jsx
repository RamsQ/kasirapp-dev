import React from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Dashboard/Pagination";
import { IconPlus, IconTicket, IconTrash, IconPackage, IconTag, IconGift } from "@tabler/icons-react";
import Swal from "sweetalert2";

export default function Index({ discounts }) {
    const handleDelete = (id) => {
        Swal.fire({
            title: "Hapus Promo?",
            text: "Tindakan ini tidak dapat dibatalkan!",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#ef4444",
            confirmButtonText: "Ya, Hapus!",
            cancelButtonText: "Batal",
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route("discounts.destroy", id));
            }
        });
    };

    return (
        <>
            <Head title="Manajemen Diskon" />
            <div className="space-y-6">
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <IconTicket className="text-primary-500" /> Manajemen Promo & Diskon
                        </h1>
                        <p className="text-sm text-slate-500 mt-1">Kelola diskon khusus produk, potongan invoice, atau promo beli gratis produk.</p>
                    </div>
                    <Link href={route("discounts.create")} className="bg-primary-500 text-white px-4 py-2 rounded-xl flex items-center gap-2 hover:bg-primary-600 transition-colors shadow-lg shadow-primary-500/20">
                        <IconPlus size={18} /> Tambah Promo
                    </Link>
                </div>

                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                    <table className="w-full text-sm text-left">
                        <thead className="bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 uppercase font-bold text-[11px] tracking-wider">
                            <tr>
                                <th className="px-6 py-4">Nama Promo</th>
                                <th className="px-6 py-4">Target / Syarat</th>
                                <th className="px-6 py-4">Tipe</th>
                                <th className="px-6 py-4">Potongan / Hadiah</th>
                                <th className="px-6 py-4">Min. Transaksi</th>
                                <th className="px-6 py-4">Periode</th>
                                <th className="px-6 py-4 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {discounts.data.map((item) => (
                                <tr key={item.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                    <td className="px-6 py-4 font-bold text-slate-900 dark:text-white">
                                        {item.name}
                                    </td>
                                    
                                    <td className="px-6 py-4">
                                        {item.product ? (
                                            <div className="flex flex-col">
                                                <span className="flex items-center gap-1 text-blue-600 dark:text-blue-400 font-bold text-[10px] uppercase">
                                                    <IconPackage size={14} /> Produk Utama
                                                </span>
                                                <span className="text-xs text-slate-600 dark:text-slate-300 truncate max-w-[150px]">
                                                    {item.product.title}
                                                </span>
                                            </div>
                                        ) : (
                                            <span className="flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-bold text-[10px] uppercase">
                                                <IconTag size={14} /> Global (Invoice)
                                            </span>
                                        )}
                                    </td>

                                    <td className="px-6 py-4">
                                        <span className={`text-[10px] font-black px-2 py-1 rounded-md uppercase ${
                                            item.type === 'percentage' 
                                            ? 'bg-indigo-100 text-indigo-700' 
                                            : item.type === 'fixed'
                                            ? 'bg-amber-100 text-amber-700'
                                            : 'bg-emerald-100 text-emerald-700'
                                        }`}>
                                            {item.type === 'percentage' ? 'Persen' : item.type === 'fixed' ? 'Nominal' : 'Beli X Get Y'}
                                        </span>
                                    </td>

                                    <td className="px-6 py-4 font-black">
                                        {item.type === 'buy_get' ? (
                                            <div className="flex flex-col">
                                                <span className="text-[10px] text-emerald-600 uppercase flex items-center gap-1">
                                                    <IconGift size={14}/> Gratis Produk:
                                                </span>
                                                <span className="text-xs text-slate-700 dark:text-slate-200">
                                                    {item.bonus_product?.title || 'Item Bonus'}
                                                </span>
                                            </div>
                                        ) : (
                                            <span className="text-slate-700 dark:text-slate-200">
                                                {item.type === 'percentage' 
                                                    ? `${item.value}%` 
                                                    : `Rp ${Number(item.value).toLocaleString('id-ID')}`
                                                }
                                            </span>
                                        )}
                                    </td>

                                    <td className="px-6 py-4 text-slate-500 dark:text-slate-400 italic">
                                        {item.type === 'buy_get' 
                                            ? `Beli ${item.min_transaction} Qty` 
                                            : `Min. Rp ${Number(item.min_transaction).toLocaleString('id-ID')}`
                                        }
                                    </td>

                                    <td className="px-6 py-4 text-slate-500 dark:text-slate-400 text-[10px] font-medium uppercase tracking-tighter">
                                        {item.start_date} <span className="text-slate-300 mx-1">/</span> {item.end_date}
                                    </td>

                                    <td className="px-6 py-4 text-center">
                                        <button 
                                            onClick={() => handleDelete(item.id)} 
                                            className="text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 p-2 rounded-xl transition-all active:scale-95"
                                        >
                                            <IconTrash size={18} />
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {discounts.data.length === 0 && (
                        <div className="p-12 text-center text-slate-500 dark:text-slate-400">
                            <IconTicket size={48} className="mx-auto mb-4 opacity-20" />
                            <p className="font-medium">Belum ada promo aktif ditemukan.</p>
                        </div>
                    )}
                </div>
                
                <div className="flex justify-center">
                    <Pagination links={discounts.links} />
                </div>
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;