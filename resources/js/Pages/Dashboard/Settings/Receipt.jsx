import React, { useState } from "react";
import { Head, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconReceipt, IconDeviceFloppy, IconPhotoCheck, IconPhotoOff } from "@tabler/icons-react";
import Swal from "sweetalert2";

export default function ReceiptSetting({ setting }) {
    const { data, setData, post, processing, errors } = useForm({
        store_name: setting?.store_name || "",
        store_address: setting?.store_address || "",
        store_phone: setting?.store_phone || "",
        store_footer: setting?.store_footer || "Terima Kasih",
        show_logo: setting?.show_logo ?? 1, // Default 1 (Tampil)
        store_logo: null,
        _method: 'POST',
    });

    const [logoUrl, setLogoUrl] = useState(
        setting?.store_logo ? `/storage/receipt/${setting.store_logo}` : null
    );

    const submit = (e) => {
        e.preventDefault();
        post(route("settings.receipt.update"), {
            forceFormData: true, 
            onSuccess: () => {
                Swal.fire({
                    icon: "success",
                    title: "Berhasil!",
                    text: "Pengaturan struk berhasil diperbarui.",
                    showConfirmButton: false,
                    timer: 1500
                });
            },
        });
    };

    return (
        <>
            <Head title="Pengaturan Struk" />
            <div className="max-w-4xl mx-auto space-y-6">
                <div className="flex items-center gap-2 mb-6">
                    <div className="p-3 bg-primary-500/10 rounded-2xl">
                        <IconReceipt size={28} className="text-primary-500" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                            Pengaturan Struk
                        </h1>
                        <p className="text-sm text-slate-500">
                            Atur logo, nama, dan info toko pada struk thermal.
                        </p>
                    </div>
                </div>

                <div className="bg-white dark:bg-slate-900 p-8 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-sm">
                    <form onSubmit={submit} className="space-y-8">
                        
                        {/* Konfigurasi Logo */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-8 items-center bg-slate-50 dark:bg-slate-800/50 p-6 rounded-2xl border border-dashed border-slate-200 dark:border-slate-700">
                            <div>
                                <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-4">
                                    Logo Toko & Opsi Tampilan
                                </label>
                                
                                <div className="flex flex-col gap-4">
                                    {/* Toggle Switch Tampilkan Logo */}
                                    <label className="relative inline-flex items-center cursor-pointer group">
                                        <input 
                                            type="checkbox" 
                                            className="sr-only peer"
                                            checked={data.show_logo == 1}
                                            onChange={(e) => setData("show_logo", e.target.checked ? 1 : 0)}
                                        />
                                        <div className="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-primary-500"></div>
                                        <span className="ml-3 text-sm font-medium text-slate-600 dark:text-slate-400 group-hover:text-slate-900 dark:group-hover:text-white transition-colors">
                                            {data.show_logo == 1 ? "Logo Ditampilkan" : "Logo Disembunyikan"}
                                        </span>
                                    </label>

                                    <input
                                        type="file"
                                        accept="image/*"
                                        onChange={(e) => {
                                            setData("store_logo", e.target.files[0]);
                                            if(e.target.files[0]) {
                                                setLogoUrl(URL.createObjectURL(e.target.files[0]));
                                            }
                                        }}
                                        className="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white file:text-slate-700 shadow-sm hover:file:bg-slate-100 cursor-pointer"
                                    />
                                    <p className="text-[10px] text-slate-400 italic">Disarankan: Gambar Hitam Putih (Sangat Kontras).</p>
                                    {errors.store_logo && <p className="text-red-500 text-xs font-bold">{errors.store_logo}</p>}
                                </div>
                            </div>

                            <div className="flex justify-center">
                                <div className={`relative p-4 rounded-2xl border-2 transition-all duration-300 ${data.show_logo == 1 ? 'border-primary-500 bg-white opacity-100' : 'border-slate-300 bg-slate-100 opacity-50'}`}>
                                    {logoUrl ? (
                                        <img src={logoUrl} alt="Preview" className="h-24 w-auto object-contain grayscale" />
                                    ) : (
                                        <div className="h-24 w-24 flex items-center justify-center text-slate-400">
                                            <IconPhotoOff size={40} />
                                        </div>
                                    )}
                                    <div className={`absolute -top-3 -right-3 p-1 rounded-full shadow-lg ${data.show_logo == 1 ? 'bg-primary-500 text-white' : 'bg-slate-400 text-white'}`}>
                                        {data.show_logo == 1 ? <IconPhotoCheck size={18} /> : <IconPhotoOff size={18} />}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Input Identitas Toko */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="space-y-2">
                                <label className="text-sm font-bold dark:text-white">Nama Toko</label>
                                <input 
                                    type="text" 
                                    value={data.store_name} 
                                    onChange={e => setData("store_name", e.target.value)} 
                                    className="w-full px-4 py-3 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-white transition-all" 
                                    placeholder="Contoh: Kopi Kita"
                                />
                                {errors.store_name && <p className="text-red-500 text-xs font-bold">{errors.store_name}</p>}
                            </div>
                            <div className="space-y-2">
                                <label className="text-sm font-bold dark:text-white">Nomor Telepon</label>
                                <input 
                                    type="text" 
                                    value={data.store_phone} 
                                    onChange={e => setData("store_phone", e.target.value)} 
                                    className="w-full px-4 py-3 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-white transition-all" 
                                    placeholder="0812xxxx"
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <label className="text-sm font-bold dark:text-white">Alamat Lengkap</label>
                            <textarea 
                                rows="3" 
                                value={data.store_address} 
                                onChange={e => setData("store_address", e.target.value)} 
                                className="w-full px-4 py-3 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-white transition-all"
                                placeholder="Jalan Raya No. 123..."
                            ></textarea>
                        </div>

                        <div className="space-y-2">
                            <label className="text-sm font-bold dark:text-white">Pesan Footer (Bawah Struk)</label>
                            <input 
                                type="text" 
                                value={data.store_footer} 
                                onChange={e => setData("store_footer", e.target.value)} 
                                className="w-full px-4 py-3 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-white transition-all" 
                                placeholder="Terima Kasih Telah Berkunjung"
                            />
                        </div>

                        <div className="pt-6 border-t border-slate-100 dark:border-slate-800 flex justify-end">
                            <button 
                                type="submit" 
                                disabled={processing} 
                                className="inline-flex items-center gap-2 px-8 py-4 bg-primary-500 hover:bg-primary-600 text-white font-bold rounded-2xl shadow-xl shadow-primary-500/30 transition-all active:scale-95 disabled:opacity-50"
                            >
                                <IconDeviceFloppy size={22} />
                                {processing ? "Menyimpan..." : "Simpan Pengaturan"}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}

ReceiptSetting.layout = (page) => <DashboardLayout children={page} />;