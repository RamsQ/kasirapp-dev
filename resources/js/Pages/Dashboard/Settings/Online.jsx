import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, router } from "@inertiajs/react";
import { 
    IconSettings, 
    IconDeviceFloppy, 
    IconPercentage, 
    IconMoneybag, 
    IconWorld, 
    IconPlus, 
    IconTrash,
    IconInfoCircle 
} from "@tabler/icons-react";
import Swal from "sweetalert2";

export default function Online({ settings = [] }) {
    // Form untuk menambah platform baru
    const { data, setData, post, processing, errors, reset } = useForm({
        name: "",
        markup_percent: 0,
        additional_fee: 0,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("online_settings.store"), {
            onSuccess: () => {
                reset();
                Swal.fire({
                    title: 'Berhasil!',
                    text: 'Platform online baru berhasil ditambahkan.',
                    icon: 'success',
                    showConfirmButton: false,
                    timer: 1500
                });
            },
        });
    };

    const deletePlatform = (id) => {
        Swal.fire({
            title: 'Hapus Platform?',
            text: "Platform ini tidak akan tersedia lagi di kasir.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route("online_settings.destroy", id), {
                    onSuccess: () => {
                        Swal.fire({
                            title: 'Dihapus!',
                            text: 'Platform berhasil dihapus.',
                            icon: 'success',
                            showConfirmButton: false,
                            timer: 1500
                        });
                    }
                });
            }
        });
    };

    return (
        <>
            <Head title="Multi-Platform Online" />
            
            <div className="mb-6 flex flex-col">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2 uppercase tracking-tight">
                    <IconWorld size={28} className="text-primary-500" />
                    Manajemen Harga Online
                </h1>
                <p className="text-sm text-slate-500 dark:text-slate-400 font-medium uppercase tracking-wider">
                    Tambah dan kelola markup harga untuk berbagai platform eksternal.
                </p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                {/* FORM TAMBAH PLATFORM */}
                <div className="lg:col-span-1">
                    <div className="bg-white dark:bg-slate-900 p-6 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-sm">
                        <h3 className="text-sm font-black uppercase mb-4 flex items-center gap-2 dark:text-white">
                            <IconPlus size={18} className="text-primary-500" /> Tambah Platform
                        </h3>
                        <form onSubmit={submit} className="space-y-4">
                            <div>
                                <label className="block text-[10px] font-black uppercase text-slate-500 mb-1">Nama Platform</label>
                                <input 
                                    type="text" 
                                    className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-primary-500" 
                                    placeholder="Contoh: GrabFood"
                                    value={data.name} 
                                    onChange={e => setData('name', e.target.value)} 
                                />
                                {errors.name && <span className="text-red-500 text-[10px] mt-1 font-bold italic">{errors.name}</span>}
                            </div>

                            <div>
                                <label className="block text-[10px] font-black uppercase text-slate-500 mb-1 flex items-center gap-1">
                                    <IconPercentage size={12} /> Markup (%)
                                </label>
                                <input 
                                    type="number" 
                                    className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-slate-900 dark:text-white text-sm" 
                                    value={data.markup_percent} 
                                    onChange={e => setData('markup_percent', e.target.value)} 
                                />
                            </div>

                            <div>
                                <label className="block text-[10px] font-black uppercase text-slate-500 mb-1 flex items-center gap-1">
                                    <IconMoneybag size={12} /> Biaya Flat (Rp)
                                </label>
                                <input 
                                    type="number" 
                                    className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-slate-900 dark:text-white text-sm" 
                                    value={data.additional_fee} 
                                    onChange={e => setData('additional_fee', e.target.value)} 
                                />
                            </div>

                            <button 
                                type="submit" 
                                disabled={processing}
                                className="w-full bg-primary-500 hover:bg-primary-600 text-white py-3 rounded-xl font-black text-[10px] uppercase flex items-center justify-center gap-2 transition-all active:scale-95 shadow-lg shadow-primary-200 dark:shadow-none"
                            >
                                <IconDeviceFloppy size={16} /> 
                                {processing ? 'PROSES...' : 'SIMPAN PLATFORM'}
                            </button>
                        </form>
                    </div>
                </div>

                {/* DAFTAR PLATFORM AKTIF */}
                <div className="lg:col-span-2">
                    <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                        <div className="p-5 border-b dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50">
                            <h3 className="text-xs font-black uppercase dark:text-white">Platform Terdaftar</h3>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm text-left">
                                <thead className="text-[10px] text-slate-500 uppercase bg-slate-50 dark:bg-slate-800 border-b dark:border-slate-700 font-black">
                                    <tr>
                                        <th className="px-6 py-4">Nama Platform</th>
                                        <th className="px-6 py-4 text-center">Markup (%)</th>
                                        <th className="px-6 py-4 text-center">Biaya Flat</th>
                                        <th className="px-6 py-4 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {settings.length > 0 ? settings.map((s) => (
                                        <tr key={s.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                            <td className="px-6 py-4 font-bold text-slate-700 dark:text-slate-200 uppercase tracking-tight italic">
                                                {s.name}
                                            </td>
                                            <td className="px-6 py-4 text-center font-black text-emerald-500">
                                                {s.markup_percent}%
                                            </td>
                                            <td className="px-6 py-4 text-center font-black text-blue-500">
                                                Rp {new Intl.NumberFormat("id-ID").format(s.additional_fee)}
                                            </td>
                                            <td className="px-6 py-4 text-center">
                                                <button 
                                                    onClick={() => deletePlatform(s.id)}
                                                    className="p-2 bg-red-50 dark:bg-red-950/30 text-red-500 rounded-xl hover:bg-red-500 hover:text-white transition-all active:scale-90"
                                                >
                                                    <IconTrash size={18} />
                                                </button>
                                            </td>
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan="4" className="px-6 py-10 text-center text-slate-400 font-black uppercase text-[10px] italic opacity-50">
                                                Belum ada platform kustom yang ditambahkan
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    {/* INFO BOX */}
                    <div className="mt-4 p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-100 dark:border-blue-900 rounded-2xl flex gap-3">
                        <IconInfoCircle className="text-blue-500 shrink-0" size={20} />
                        <p className="text-[10px] text-blue-700 dark:text-blue-400 font-bold leading-relaxed">
                            PILIHAN PLATFORM AKAN MUNCUL DI LAYAR KASIR (POS). HARGA PRODUK AKAN OTOMATIS BERUBAH SESUAI RUMUS: (HARGA JUAL + MARKUP %) + BIAYA FLAT.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}

Online.layout = (page) => <DashboardLayout children={page} />;