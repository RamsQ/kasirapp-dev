import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, router, usePage, Link } from "@inertiajs/react"; // Menambahkan Link
import { IconArmchair, IconPlus, IconTrash, IconEdit, IconGitMerge, IconInfoCircle, IconPrinter } from "@tabler/icons-react"; // Menambahkan IconPrinter
import Swal from "sweetalert2";
import Pagination from "@/Components/Dashboard/Pagination";

export default function TableIndex({ auth, tables }) {
    // Ambil data holds dari props (dikirim melalui HandleInertiaRequests atau Controller)
    const { holds } = usePage().props;

    const [showModal, setShowModal] = useState(false);
    const [isEdit, setIsEdit] = useState(false);

    // Form logic
    const { data, setData, post, put, reset, processing, errors } = useForm({
        id: "",
        name: "",
    });

    const handleAdd = () => {
        setIsEdit(false);
        reset();
        setShowModal(true);
    };

    const handleEdit = (table) => {
        setIsEdit(true);
        setData({
            id: table.id,
            name: table.name,
        });
        setShowModal(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(route("tables.update", data.id), {
                onSuccess: () => {
                    setShowModal(false);
                    Swal.fire("Berhasil", "Meja diperbarui", "success");
                },
            });
        } else {
            post(route("tables.store"), {
                onSuccess: () => {
                    setShowModal(false);
                    reset();
                    Swal.fire("Berhasil", "Meja ditambahkan", "success");
                },
            });
        }
    };

    const handleDelete = (id) => {
        Swal.fire({
            title: "Hapus Meja?",
            text: "Data meja akan dihapus permanen.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "Ya, Hapus",
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route("tables.destroy", id));
            }
        });
    };

    // Helper untuk mencari apakah meja sedang dalam status merged
    const getMergeInfo = (tableId) => {
        const activeOrder = holds?.find(h => h.table_id === tableId);
        if (activeOrder && activeOrder.ref_number.includes('[Merged')) {
            // Ambil teks di dalam kurung [Merged ...]
            const match = activeOrder.ref_number.match(/\[(.*?)\]/);
            return match ? match[1] : "Merged";
        }
        return null;
    };

    return (
        <DashboardLayout user={auth.user}>
            <Head title="Master Meja" />

            <div className="flex flex-col gap-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold text-slate-800 dark:text-white">Master Meja</h2>
                        <p className="text-slate-500 dark:text-slate-400 text-sm">Kelola template meja dan cetak QR Menu.</p>
                    </div>
                    
                    {/* ACTION BUTTONS */}
                    <div className="flex items-center gap-3">
                        {/* TOMBOL CETAK QR - BARU */}
                        <Link
                            href={route("tables.printQr")}
                            className="flex items-center gap-2 px-4 py-2 bg-slate-800 text-white rounded-xl font-bold hover:bg-slate-700 transition-all shadow-lg"
                        >
                            <IconPrinter size={20} /> <span className="hidden sm:inline">Cetak QR</span>
                        </Link>

                        <button
                            onClick={handleAdd}
                            className="flex items-center gap-2 px-4 py-2 bg-primary-600 text-white rounded-xl font-bold hover:bg-primary-700 transition-all shadow-lg shadow-primary-500/20"
                        >
                            <IconPlus size={20} /> Tambah Meja
                        </button>
                    </div>
                </div>

                <div className="bg-white dark:bg-slate-900 rounded-3xl border dark:border-slate-800 shadow-sm overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead className="bg-slate-50 dark:bg-slate-800/50 border-b dark:border-slate-800">
                                <tr>
                                    <th className="px-6 py-4 text-xs font-black uppercase text-slate-400">Nama Meja</th>
                                    <th className="px-6 py-4 text-xs font-black uppercase text-slate-400">Status</th>
                                    <th className="px-6 py-4 text-xs font-black uppercase text-slate-400">Keterangan Operasional</th>
                                    <th className="px-6 py-4 text-xs font-black uppercase text-slate-400 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y dark:divide-slate-800">
                                {tables.data.map((table) => {
                                    const mergeInfo = getMergeInfo(table.id);
                                    return (
                                        <tr key={table.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group">
                                            <td className="px-6 py-4 font-bold text-slate-700 dark:text-slate-200">
                                                <div className="flex items-center gap-3">
                                                    <div className={`p-2 rounded-lg ${table.status === 'occupied' ? 'bg-orange-100 dark:bg-orange-900/30 text-orange-600' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'}`}>
                                                        <IconArmchair size={18} />
                                                    </div>
                                                    {table.name}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase border ${
                                                    table.status === 'available' 
                                                    ? 'bg-green-50 text-green-600 border-green-100 dark:bg-green-900/20 dark:border-green-800' 
                                                    : 'bg-red-50 text-red-600 border-red-100 dark:bg-red-900/20 dark:border-red-800'
                                                }`}>
                                                    {table.status === 'available' ? 'Tersedia' : 'Terisi'}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4">
                                                {mergeInfo ? (
                                                    <div className="flex items-center gap-2 text-indigo-500 font-bold text-xs italic">
                                                        <IconGitMerge size={14} />
                                                        {mergeInfo}
                                                    </div>
                                                ) : table.status === 'occupied' ? (
                                                    <div className="flex items-center gap-2 text-slate-400 text-xs italic">
                                                        <IconInfoCircle size={14} />
                                                        Sedang digunakan
                                                    </div>
                                                ) : (
                                                    <span className="text-slate-300 dark:text-slate-700">-</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <button 
                                                        onClick={() => handleEdit(table)} 
                                                        className="p-2 text-slate-400 hover:text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-all"
                                                        title="Edit Nama"
                                                    >
                                                        <IconEdit size={18} />
                                                    </button>
                                                    <button 
                                                        onClick={() => handleDelete(table.id)} 
                                                        disabled={table.status === 'occupied'}
                                                        className={`p-2 rounded-lg transition-all ${table.status === 'occupied' ? 'text-slate-200 cursor-not-allowed' : 'text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20'}`}
                                                        title={table.status === 'occupied' ? 'Meja Terisi Tidak Bisa Dihapus' : 'Hapus Meja'}
                                                    >
                                                        <IconTrash size={18} />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
                <Pagination links={tables.links} />
            </div>

            {/* Modal Form */}
            {showModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
                    <div className="bg-white dark:bg-slate-900 w-full max-w-md rounded-[2.5rem] p-8 shadow-2xl animate-in zoom-in duration-200 border dark:border-slate-800">
                        <div className="flex items-center gap-4 mb-6">
                            <div className="w-12 h-12 bg-primary-100 dark:bg-primary-900/30 text-primary-600 rounded-2xl flex items-center justify-center">
                                <IconArmchair size={28} />
                            </div>
                            <h3 className="text-xl font-black uppercase dark:text-white">
                                {isEdit ? "Update Meja" : "Meja Baru"}
                            </h3>
                        </div>
                        
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div>
                                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Label / Nomor Meja</label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData("name", e.target.value)}
                                    className="w-full mt-2 py-4 px-5 rounded-2xl border-none bg-slate-100 dark:bg-slate-800 dark:text-white font-bold focus:ring-2 focus:ring-primary-500 transition-all shadow-inner"
                                    placeholder="Contoh: Meja 01 atau VIP 1"
                                    required
                                    autoFocus
                                />
                                {errors.name && <p className="text-red-500 text-[10px] font-bold mt-2 ml-1 uppercase">{errors.name}</p>}
                            </div>
                            <div className="flex gap-3 pt-2">
                                <button 
                                    type="button" 
                                    onClick={() => setShowModal(false)} 
                                    className="flex-1 py-4 text-slate-500 font-black uppercase text-[10px] tracking-widest hover:text-slate-700 transition-colors"
                                >
                                    Batal
                                </button>
                                <button 
                                    type="submit" 
                                    disabled={processing} 
                                    className="flex-1 py-4 bg-primary-600 text-white rounded-2xl font-black uppercase text-[10px] tracking-widest shadow-xl shadow-primary-500/20 active:scale-95 transition-all disabled:opacity-50"
                                >
                                    {processing ? "Loading..." : "Simpan Data"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </DashboardLayout>
    );
}