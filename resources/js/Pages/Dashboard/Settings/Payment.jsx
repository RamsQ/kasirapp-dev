import React, { useEffect, useState } from "react";
import { Head, useForm, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Input from "@/Components/Dashboard/Input";
import Checkbox from "@/Components/Dashboard/Checkbox";
import {
    IconCreditCard,
    IconDeviceFloppy,
    IconBrandStripe,
    IconCash,
    IconQrcode,
    IconPhoto,
    IconInfoCircle,
    IconShieldCheck,
    IconSettings,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

export default function Payment({ setting, supportedGateways = [] }) {
    const { flash } = usePage().props;

    // State untuk preview gambar QRIS
    const [qrisPreview, setQrisPreview] = useState(
        setting?.qris_manual_image ? `/storage/payments/${setting.qris_manual_image}` : null
    );

    const { data, setData, post, errors, processing } = useForm({
        _method: "PUT", // Spoofing agar Laravel dapat membaca file pada request PUT
        default_gateway: setting?.default_gateway ?? "cash",
        
        // Midtrans Data
        midtrans_enabled: !!setting?.midtrans_enabled,
        midtrans_server_key: setting?.midtrans_server_key ?? "",
        midtrans_client_key: setting?.midtrans_client_key ?? "",
        midtrans_production: !!setting?.midtrans_production,
        
        // Xendit Data
        xendit_enabled: !!setting?.xendit_enabled,
        xendit_secret_key: setting?.xendit_secret_key ?? "",
        xendit_public_key: setting?.xendit_public_key ?? "",
        xendit_production: !!setting?.xendit_production,
        
        // QRIS Manual Data
        qris_manual_enabled: !!setting?.qris_manual_enabled,
        qris_manual_image: null,
    });

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    const handleImageChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setData("qris_manual_image", file);
            // Bersihkan URL lama jika ada untuk optimasi memori
            if (qrisPreview && qrisPreview.startsWith('blob:')) {
                URL.revokeObjectURL(qrisPreview);
            }
            setQrisPreview(URL.createObjectURL(file));
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route("settings.payments.update"), { 
            preserveScroll: true,
            forceFormData: true, // Memastikan pengiriman sebagai multipart/form-data
        });
    };

    const isGatewaySelectable = (gatewayValue) => {
        if (gatewayValue === "cash") return true;
        if (gatewayValue === "midtrans") return data.midtrans_enabled;
        if (gatewayValue === "xendit") return data.xendit_enabled;
        if (gatewayValue === "qris") return data.qris_manual_enabled;
        return false;
    };

    return (
        <>
            <Head title="Pengaturan Pembayaran" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconCreditCard size={28} className="text-primary-500" />
                    Pengaturan Pembayaran
                </h1>
                <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Konfigurasi integrasi Payment Gateway otomatis dan metode QRIS Statis
                </p>
            </div>

            <form onSubmit={handleSubmit} className="max-w-4xl space-y-6 pb-20">
                
                {/* Gateway Default & Global Settings */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 shadow-sm">
                    <h3 className="text-sm font-bold text-slate-800 dark:text-slate-200 mb-4 flex items-center gap-2">
                        <IconSettings size={18} className="text-primary-500" />
                        Konfigurasi Utama
                    </h3>
                    <div className="grid md:grid-cols-2 gap-6">
                        <div>
                            <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                                Metode Pembayaran Utama (Default)
                            </label>
                            <select
                                value={data.default_gateway}
                                onChange={(e) => setData("default_gateway", e.target.value)}
                                className="w-full h-11 px-4 text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-primary-500 transition-all outline-none"
                            >
                                {supportedGateways.map((gw) => (
                                    <option
                                        key={gw.value}
                                        value={gw.value}
                                        disabled={!isGatewaySelectable(gw.value)}
                                    >
                                        {gw.label} {!isGatewaySelectable(gw.value) && "(Aktifkan modul di bawah dulu)"}
                                    </option>
                                ))}
                            </select>
                            <p className="mt-2 text-[11px] text-slate-500 flex items-center gap-1">
                                <IconInfoCircle size={12} /> Metode ini akan terpilih otomatis di layar kasir.
                            </p>
                        </div>
                    </div>
                </div>

                {/* QRIS MANUAL SECTION */}
                <div className={`bg-white dark:bg-slate-900 rounded-2xl border transition-all p-6 ${data.qris_manual_enabled ? 'border-primary-500 ring-4 ring-primary-500/5' : 'border-slate-200 dark:border-slate-800'}`}>
                    <div className="flex items-center justify-between mb-6">
                        <div className="flex items-center gap-3">
                            <div className={`p-2 rounded-lg ${data.qris_manual_enabled ? 'bg-primary-500 text-white' : 'bg-slate-100 text-slate-400'}`}>
                                <IconQrcode size={20} />
                            </div>
                            <div>
                                <h3 className="text-sm font-bold text-slate-800 dark:text-slate-200">QRIS Manual (Statis)</h3>
                                <p className="text-xs text-slate-500">Tampilkan gambar QRIS untuk di-scan pelanggan</p>
                            </div>
                        </div>
                        <label className="relative inline-flex items-center cursor-pointer">
                            <Checkbox
                                checked={data.qris_manual_enabled}
                                onChange={(e) => setData("qris_manual_enabled", e.target.checked)}
                            />
                        </label>
                    </div>

                    {data.qris_manual_enabled && (
                        <div className="grid md:grid-cols-2 gap-8 animate-in fade-in slide-in-from-top-2">
                            <div className="space-y-4">
                                <Input
                                    label="Unggah File QRIS"
                                    type="file"
                                    onChange={handleImageChange}
                                    accept="image/*"
                                    errors={errors?.qris_manual_image}
                                    className="file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100"
                                />
                                <div className="p-3 bg-amber-50 dark:bg-amber-950/20 rounded-xl border border-amber-100 dark:border-amber-900">
                                    <p className="text-[11px] text-amber-700 dark:text-amber-400 leading-relaxed">
                                        <strong>Tips:</strong> Gunakan gambar dengan rasio 1:1 (persegi) agar tampilan di tablet kasir tidak terpotong.
                                    </p>
                                </div>
                            </div>
                            <div className="flex flex-col items-center justify-center border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl p-6 bg-slate-50 dark:bg-slate-800/50">
                                <span className="text-[10px] font-bold text-slate-400 uppercase mb-3 tracking-widest">Preview Tampilan Kasir</span>
                                {qrisPreview ? (
                                    <div className="relative group">
                                        <img src={qrisPreview} className="h-44 w-44 object-contain shadow-2xl rounded-xl bg-white p-3 border border-slate-200" alt="QRIS Preview" />
                                        <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity rounded-xl flex items-center justify-center text-white text-xs font-bold">
                                            QRIS Aktif
                                        </div>
                                    </div>
                                ) : (
                                    <div className="h-44 w-44 flex flex-col items-center justify-center text-slate-300">
                                        <IconPhoto size={48} stroke={1} />
                                        <span className="text-[10px] mt-2">Belum ada gambar</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </div>

                {/* MIDTRANS SECTION */}
                <div className={`bg-white dark:bg-slate-900 rounded-2xl border transition-all p-6 ${data.midtrans_enabled ? 'border-blue-500 ring-4 ring-blue-500/5' : 'border-slate-200 dark:border-slate-800'}`}>
                    <div className="flex items-center justify-between mb-6">
                        <div className="flex items-center gap-3">
                            <div className={`p-2 rounded-lg ${data.midtrans_enabled ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-400'}`}>
                                <IconBrandStripe size={20} />
                            </div>
                            <div>
                                <h3 className="text-sm font-bold text-slate-800 dark:text-slate-200">Midtrans Snap</h3>
                                <p className="text-xs text-slate-500">Integrasi pembayaran otomatis (QRIS Dinamis, E-Wallet, VA)</p>
                            </div>
                        </div>
                        <Checkbox
                            checked={data.midtrans_enabled}
                            onChange={(e) => setData("midtrans_enabled", e.target.checked)}
                        />
                    </div>

                    {data.midtrans_enabled && (
                        <div className="space-y-6 animate-in fade-in slide-in-from-top-2">
                            <div className="flex items-center gap-4 p-3 bg-blue-50 dark:bg-blue-950/30 rounded-xl border border-blue-100 dark:border-blue-900">
                                <IconShieldCheck size={20} className="text-blue-600" />
                                <div className="flex-1">
                                    <p className="text-xs font-bold text-blue-800 dark:text-blue-300">Mode Lingkungan</p>
                                    <div className="flex items-center gap-4 mt-1">
                                        <label className="flex items-center gap-2 cursor-pointer">
                                            <input type="radio" name="midtrans_mode" checked={!data.midtrans_production} onChange={() => setData("midtrans_production", false)} className="text-blue-600 focus:ring-blue-500" />
                                            <span className="text-xs font-medium text-slate-600 dark:text-slate-400">Sandbox (Testing)</span>
                                        </label>
                                        <label className="flex items-center gap-2 cursor-pointer">
                                            <input type="radio" name="midtrans_mode" checked={data.midtrans_production} onChange={() => setData("midtrans_production", true)} className="text-blue-600 focus:ring-blue-500" />
                                            <span className="text-xs font-medium text-slate-600 dark:text-slate-400">Production (Live)</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div className="grid gap-4 md:grid-cols-2">
                                <Input
                                    label="Server Key"
                                    placeholder="Contoh: SB-Mid-server-..."
                                    value={data.midtrans_server_key}
                                    onChange={(e) => setData("midtrans_server_key", e.target.value)}
                                    errors={errors?.midtrans_server_key}
                                />
                                <Input
                                    label="Client Key"
                                    placeholder="Contoh: SB-Mid-client-..."
                                    value={data.midtrans_client_key}
                                    onChange={(e) => setData("midtrans_client_key", e.target.value)}
                                    errors={errors?.midtrans_client_key}
                                />
                            </div>
                        </div>
                    )}
                </div>

                {/* Actions */}
                <div className="flex items-center justify-between p-6 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-200 dark:border-slate-800">
                    <div className="flex items-center gap-2 text-slate-500">
                        <IconInfoCircle size={16} />
                        <span className="text-xs">Pastikan API Keys sudah sesuai dengan dashboard masing-masing vendor.</span>
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center gap-2 px-10 py-3 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-bold transition-all shadow-lg shadow-primary-500/25 disabled:opacity-50 active:scale-95"
                    >
                        {processing ? (
                            <div className="h-5 w-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                        ) : (
                            <IconDeviceFloppy size={20} />
                        )}
                        Simpan Semua Perubahan
                    </button>
                </div>
            </form>
        </>
    );
}

Payment.layout = (page) => <DashboardLayout children={page} />;