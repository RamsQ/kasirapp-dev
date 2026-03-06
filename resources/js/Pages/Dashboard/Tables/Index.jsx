import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, router, usePage, Link } from "@inertiajs/react";
import { 
    IconArmchair, 
    IconPlus, 
    IconTrash, 
    IconEdit, 
    IconGitMerge, 
    IconInfoCircle, 
    IconPrinter, 
    IconShoppingCart, 
    IconQrcode, 
    IconDeviceMobile, 
    IconDownload 
} from "@tabler/icons-react";
import Swal from "sweetalert2";
import Pagination from "@/Components/Dashboard/Pagination";
import { QRCodeSVG } from "qrcode.react"; // Pastikan library ini terinstall

export default function TableIndex({ auth, tables }) {
    // Ambil data holds dari props
    const { holds } = usePage().props;

    const [showModal, setShowModal] = useState(false);
    const [isEdit, setIsEdit] = useState(false);
    const [activeTab, setActiveTab] = useState("dine-in"); // Tab State: dine-in atau take-away

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
            confirmButtonColor: "#ef4444",
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route("tables.destroy", id));
            }
        });
    };

    const getMergeInfo = (tableId) => {
        const activeOrder = holds?.find(h => h.table_id === tableId);
        if (activeOrder && activeOrder.ref_number.includes('[Merged')) {
            const match = activeOrder.ref_number.match(/\[(.*?)\]/);
            return match ? match[1] : "Merged";
        }
        return null;
    };

    return (
        <DashboardLayout user={auth.user}>
            <Head title="Manajemen Meja & QR" />

            <div className="flex flex-col gap-8 pb-10">
                {/* 1. Header Section */}
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white uppercase tracking-tighter italic flex items-center gap-3">
                            <div className="p-2 bg-indigo-600 rounded-xl text-white shadow-lg">
                                <IconArmchair size={24} />
                            </div>
                            Master Meja & QR
                        </h1>
                        <p className="text-slate-500 dark:text-slate-400 text-sm font-medium mt-1">
                            Kelola layout meja makan dan Digital Menu QR pelanggan.
                        </p>
                    </div>
                </div>

                {/* 2. Custom Tabs Switcher */}
                <div className="flex p-1.5 bg-slate-100 dark:bg-slate-800 w-fit rounded-[1.25rem] border dark:border-slate-700 shadow-inner">
                    <button
                        onClick={() => setActiveTab("dine-in")}
                        className={`flex items-center gap-2 px-6 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all ${
                            activeTab === "dine-in"
                                ? "bg-white dark:bg-indigo-600 text-indigo-600 dark:text-white shadow-sm scale-[1.02]"
                                : "text-slate-500 hover:text-slate-700 dark:text-slate-400"
                        }`}
                    >
                        <IconArmchair size={16} /> Dine-In Management
                    </button>
                    <button
                        onClick={() => setActiveTab("take-away")}
                        className={`flex items-center gap-2 px-6 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all ${
                            activeTab === "take-away"
                                ? "bg-white dark:bg-emerald-600 text-emerald-600 dark:text-white shadow-sm scale-[1.02]"
                                : "text-slate-500 hover:text-slate-700 dark:text-slate-400"
                        }`}
                    >
                        <IconShoppingCart size={16} /> QR Take Away
                    </button>
                </div>

                {/* 3. Tab Content */}
                {activeTab === "dine-in" ? (
                    <div className="space-y-6 animate-in fade-in slide-in-from-bottom-2 duration-300">
                        <div className="flex items-center justify-between">
                            <h2 className="text-xs font-black uppercase text-slate-400 tracking-[0.2em]">Daftar Meja Aktif</h2>
                            <div className="flex gap-2">
                                <Link
                                    href={route("tables.printQr")}
                                    className="flex items-center gap-2 px-4 py-2 bg-slate-800 hover:bg-black text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition-all"
                                >
                                    <IconPrinter size={16} /> Cetak Semua QR
                                </Link>
                                <button
                                    onClick={handleAdd}
                                    className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-indigo-500/20 transition-all active:scale-95"
                                >
                                    <IconPlus size={16} /> Tambah Meja
                                </button>
                            </div>
                        </div>

                        <div className="bg-white dark:bg-slate-900 rounded-[2rem] border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left">
                                    <thead className="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">
                                        <tr>
                                            <th className="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Identitas Meja</th>
                                            <th className="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Status</th>
                                            <th className="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest">Keterangan</th>
                                            <th className="px-8 py-5 text-[10px] font-black uppercase text-slate-400 tracking-widest text-right">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-50 dark:divide-slate-800">
                                        {tables.data.map((table) => {
                                            const mergeInfo = getMergeInfo(table.id);
                                            return (
                                                <tr key={table.id} className="hover:bg-indigo-50/30 dark:hover:bg-indigo-900/10 transition-colors group">
                                                    <td className="px-8 py-5 font-bold text-slate-700 dark:text-slate-200">
                                                        <div className="flex items-center gap-4">
                                                            <div className={`w-10 h-10 rounded-xl flex items-center justify-center transition-transform group-hover:rotate-6 ${table.status === 'occupied' ? 'bg-orange-100 dark:bg-orange-950/30 text-orange-600' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'}`}>
                                                                <IconArmchair size={20} />
                                                            </div>
                                                            <span className="text-sm font-black uppercase tracking-tight">{table.name}</span>
                                                        </div>
                                                    </td>
                                                    <td className="px-8 py-5">
                                                        <span className={`px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border ${
                                                            table.status === 'available' 
                                                            ? 'bg-emerald-50 text-emerald-600 border-emerald-100 dark:bg-emerald-950/20 dark:border-emerald-900' 
                                                            : 'bg-rose-50 text-rose-600 border-rose-100 dark:bg-rose-950/20 dark:border-rose-900'
                                                        }`}>
                                                            {table.status === 'available' ? 'Tersedia' : 'Terisi'}
                                                        </span>
                                                    </td>
                                                    <td className="px-8 py-5">
                                                        {mergeInfo ? (
                                                            <div className="inline-flex items-center gap-2 text-indigo-600 dark:text-indigo-400 font-black text-[10px] uppercase italic bg-indigo-50 dark:bg-indigo-950/30 px-3 py-1 rounded-lg border border-indigo-100 dark:border-indigo-900/50">
                                                                <IconGitMerge size={14} /> {mergeInfo}
                                                            </div>
                                                        ) : table.status === 'occupied' ? (
                                                            <div className="flex items-center gap-2 text-slate-400 text-[10px] font-bold uppercase italic">
                                                                <IconInfoCircle size={14} /> Sedang Makan
                                                            </div>
                                                        ) : (
                                                            <span className="text-slate-300 dark:text-slate-700">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-8 py-5 text-right">
                                                        <div className="flex justify-end gap-1">
                                                            <button 
                                                                onClick={() => handleEdit(table)} 
                                                                className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded-xl transition-all active:scale-90"
                                                                title="Edit"
                                                            >
                                                                <IconEdit size={18} />
                                                            </button>
                                                            <button 
                                                                onClick={() => handleDelete(table.id)} 
                                                                disabled={table.status === 'occupied'}
                                                                className={`p-2 rounded-xl transition-all active:scale-90 ${table.status === 'occupied' ? 'text-slate-200 cursor-not-allowed opacity-30' : 'text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/20'}`}
                                                                title="Hapus"
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
                ) : (
                    /* TAB CONTENT: TAKE AWAY QR */
                    <div className="animate-in fade-in slide-in-from-bottom-2 duration-300">
                        <div className="max-w-2xl mx-auto">
                            <div className="relative overflow-hidden bg-white dark:bg-slate-900 rounded-[3rem] border border-slate-200 dark:border-slate-800 p-10 shadow-2xl text-center group">
                                {/* Decorative background elements */}
                                <div className="absolute top-0 right-0 p-8 opacity-[0.03] group-hover:scale-110 transition-transform duration-700 pointer-events-none">
                                    <IconShoppingCart size={240} />
                                </div>
                                <div className="absolute -left-20 -bottom-20 w-64 h-64 bg-emerald-500/5 rounded-full blur-3xl"></div>

                                <div className="relative z-10 flex flex-col items-center">
                                    <div className="w-20 h-20 bg-emerald-600 rounded-[2rem] flex items-center justify-center text-white mb-6 shadow-xl shadow-emerald-500/20">
                                        <IconShoppingCart size={40} />
                                    </div>
                                    
                                    <h3 className="text-3xl font-black uppercase tracking-tighter italic text-slate-900 dark:text-white">QR Take Away</h3>
                                    <p className="text-sm text-slate-500 font-medium max-w-sm mx-auto mt-2">
                                        Pelanggan bisa memesan dari rumah atau antrean. Pesanan akan otomatis ditandai sebagai <span className="text-emerald-600 font-black">BAWA PULANG</span>.
                                    </p>

                                    {/* Main QR Display */}
                                    <div className="mt-10 p-8 bg-slate-50 dark:bg-slate-800 rounded-[2.5rem] border-2 border-dashed border-emerald-200 dark:border-emerald-800">
                                        <div className="bg-white p-5 rounded-[2rem] shadow-inner">
                                            <QRCodeSVG 
                                                value={`${window.location.origin}/menu?type=takeaway`} 
                                                size={200}
                                                level="H"
                                            />
                                        </div>
                                        <div className="mt-4 inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-emerald-600">
                                            <IconQrcode size={14} /> Scan Digital Menu
                                        </div>
                                    </div>

                                    {/* Action Buttons */}
                                    <div className="mt-10 grid grid-cols-1 sm:grid-cols-2 gap-4 w-full">
                                        <button 
                                            onClick={() => window.open(route('print.qr.takeaway'), '_blank')}
                                            className="flex items-center justify-center gap-3 py-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-2xl font-black text-xs uppercase tracking-[0.2em] shadow-lg shadow-emerald-500/20 transition-all active:scale-95"
                                        >
                                            <IconPrinter size={18} /> Cetak QR Stand
                                        </button>
                                        <Link 
                                            href={`${window.location.origin}/menu?type=takeaway`}
                                            target="_blank"
                                            className="flex items-center justify-center gap-3 py-4 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-2xl font-black text-xs uppercase tracking-[0.2em] hover:bg-slate-200 transition-all"
                                        >
                                            <IconDeviceMobile size={18} /> Preview di HP
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Modal Form Meja (Indigo Theme) */}
            {showModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-md">
                    <div className="bg-white dark:bg-slate-900 w-full max-w-md rounded-[3rem] p-10 shadow-2xl animate-in zoom-in duration-200 border border-slate-200 dark:border-slate-800">
                        <div className="flex items-center gap-5 mb-8">
                            <div className="w-14 h-14 bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 rounded-2xl flex items-center justify-center shadow-inner">
                                <IconArmchair size={32} />
                            </div>
                            <div>
                                <h3 className="text-xl font-black uppercase tracking-tighter dark:text-white leading-none">
                                    {isEdit ? "Update Meja" : "Meja Baru"}
                                </h3>
                                <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Informasi Lokasi Makan</p>
                            </div>
                        </div>
                        
                        <form onSubmit={handleSubmit} className="space-y-8">
                            <div>
                                <label className="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] ml-1">Label / Nomor Meja</label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData("name", e.target.value)}
                                    className={`w-full mt-3 py-4 px-6 rounded-2xl border-2 transition-all font-black text-lg ${
                                        errors.name 
                                        ? 'border-rose-500 bg-rose-50 focus:ring-rose-500' 
                                        : 'border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800 dark:text-white focus:border-indigo-600 focus:ring-0 shadow-inner'
                                    }`}
                                    placeholder="Contoh: MEJA 01"
                                    required
                                    autoFocus
                                />
                                {errors.name && <p className="text-rose-500 text-[10px] font-bold mt-2 ml-1 uppercase">{errors.name}</p>}
                            </div>
                            
                            <div className="flex gap-4">
                                <button 
                                    type="button" 
                                    onClick={() => setShowModal(false)} 
                                    className="flex-1 py-4 text-slate-500 font-black uppercase text-[10px] tracking-widest hover:text-slate-800 transition-colors"
                                >
                                    Batal
                                </button>
                                <button 
                                    type="submit" 
                                    disabled={processing} 
                                    className="flex-1 py-4 bg-indigo-600 text-white rounded-2xl font-black uppercase text-[10px] tracking-[0.2em] shadow-xl shadow-indigo-500/20 active:scale-95 transition-all disabled:opacity-50"
                                >
                                    {processing ? "Saving..." : "Simpan Meja"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </DashboardLayout>
    );
}