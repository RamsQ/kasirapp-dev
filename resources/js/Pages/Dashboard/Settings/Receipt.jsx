import React, { useState } from "react";
import { Head, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconReceipt, IconDeviceFloppy, IconUpload } from "@tabler/icons-react";
import Swal from "sweetalert2";

export default function ReceiptSetting({ setting }) {
    const { data, setData, post, processing, errors } = useForm({
        store_name: setting?.store_name || "",
        store_address: setting?.store_address || "",
        store_phone: setting?.store_phone || "",
        store_footer: setting?.store_footer || "Terima Kasih",
        store_logo: null,
        _method: 'POST',
    });

    const [logoUrl, setLogoUrl] = useState(
        setting?.store_logo ? `/storage/receipt/${setting.store_logo}` : null
    );

    const submit = (e) => {
        e.preventDefault();
        post(route("settings.receipt.update"), {
            forceFormData: true, // Wajib untuk upload file
            onSuccess: () => {
                Swal.fire("Berhasil!", "Pengaturan struk disimpan.", "success");
            },
        });
    };

    return (
        <>
            <Head title="Pengaturan Struk" />
            <div className="max-w-4xl mx-auto space-y-6">
                <div className="flex items-center gap-2 mb-6">
                    <IconReceipt size={28} className="text-primary-500" />
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                            Pengaturan Struk
                        </h1>
                        <p className="text-sm text-slate-500">
                            Atur logo, nama, dan info toko pada struk.
                        </p>
                    </div>
                </div>

                <div className="bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800">
                    <form onSubmit={submit} className="space-y-6">
                        
                        {/* Upload Logo */}
                        <div>
                            <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                                Logo Toko
                            </label>
                            <div className="flex items-start gap-4">
                                {logoUrl && (
                                    <div className="border p-1 rounded bg-white">
                                        <img src={logoUrl} alt="Preview" className="h-20 w-auto object-contain" />
                                    </div>
                                )}
                                <div className="flex-1">
                                    <input
                                        type="file"
                                        accept="image/*"
                                        onChange={(e) => {
                                            setData("store_logo", e.target.files[0]);
                                            if(e.target.files[0]) {
                                                setLogoUrl(URL.createObjectURL(e.target.files[0]));
                                            }
                                        }}
                                        className="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100"
                                    />
                                    <p className="text-xs text-slate-400 mt-1">Format: JPG/PNG (Hitam Putih disarankan).</p>
                                    {errors.store_logo && <p className="text-red-500 text-xs mt-1">{errors.store_logo}</p>}
                                </div>
                            </div>
                        </div>

                        {/* Input Fields */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium mb-2 dark:text-white">Nama Toko</label>
                                <input type="text" value={data.store_name} onChange={e => setData("store_name", e.target.value)} className="w-full px-4 py-2 rounded-xl border border-slate-200 dark:bg-slate-800 dark:text-white" />
                                {errors.store_name && <p className="text-red-500 text-xs mt-1">{errors.store_name}</p>}
                            </div>
                            <div>
                                <label className="block text-sm font-medium mb-2 dark:text-white">No Telepon</label>
                                <input type="text" value={data.store_phone} onChange={e => setData("store_phone", e.target.value)} className="w-full px-4 py-2 rounded-xl border border-slate-200 dark:bg-slate-800 dark:text-white" />
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm font-medium mb-2 dark:text-white">Alamat</label>
                            <textarea rows="2" value={data.store_address} onChange={e => setData("store_address", e.target.value)} className="w-full px-4 py-2 rounded-xl border border-slate-200 dark:bg-slate-800 dark:text-white"></textarea>
                        </div>

                        <div>
                            <label className="block text-sm font-medium mb-2 dark:text-white">Footer Struk</label>
                            <input type="text" value={data.store_footer} onChange={e => setData("store_footer", e.target.value)} className="w-full px-4 py-2 rounded-xl border border-slate-200 dark:bg-slate-800 dark:text-white" />
                        </div>

                        <div className="pt-4 border-t border-slate-100 dark:border-slate-800">
                            <button type="submit" disabled={processing} className="inline-flex items-center gap-2 px-6 py-3 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-xl shadow-lg shadow-primary-500/30 transition-all disabled:opacity-50">
                                <IconDeviceFloppy size={20} />
                                Simpan Pengaturan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}

ReceiptSetting.layout = (page) => <DashboardLayout children={page} />;