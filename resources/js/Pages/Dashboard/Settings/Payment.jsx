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
} from "@tabler/icons-react";
import toast from "react-hot-toast";

export default function Payment({ setting, supportedGateways = [] }) {
    const { flash } = usePage().props;

    // State untuk preview gambar QRIS
    const [qrisPreview, setQrisPreview] = useState(
        setting?.qris_manual_image ? `/storage/payments/${setting.qris_manual_image}` : null
    );

    const { data, setData, post, errors, processing } = useForm({
        _method: "PUT", // Gunakan spoofing PUT agar bisa kirim file gambar
        default_gateway: setting?.default_gateway ?? "cash",
        midtrans_enabled: setting?.midtrans_enabled ?? false,
        midtrans_server_key: setting?.midtrans_server_key ?? "",
        midtrans_client_key: setting?.midtrans_client_key ?? "",
        midtrans_production: setting?.midtrans_production ?? false,
        xendit_enabled: setting?.xendit_enabled ?? false,
        xendit_secret_key: setting?.xendit_secret_key ?? "",
        xendit_public_key: setting?.xendit_public_key ?? "",
        xendit_production: setting?.xendit_production ?? false,
        // --- DATA QRIS BARU ---
        qris_manual_enabled: setting?.qris_manual_enabled ?? false,
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
            setQrisPreview(URL.createObjectURL(file));
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        // Gunakan post() karena Inertia hanya mendukung upload file via POST (spoofed as PUT)
        post(route("settings.payments.update"), { preserveScroll: true });
    };

    const isGatewaySelectable = (gateway) => {
        if (gateway === "cash") return true;
        if (gateway === "midtrans") return data.midtrans_enabled;
        if (gateway === "xendit") return data.xendit_enabled;
        if (gateway === "qris") return data.qris_manual_enabled; // Tambahkan ini
        return false;
    };

    return (
        <>
            <Head title="Pengaturan Payment" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconCreditCard size={28} className="text-primary-500" />
                    Pengaturan Payment Gateway
                </h1>
                <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Konfigurasi metode pembayaran otomatis dan QRIS manual
                </p>
            </div>

            <form onSubmit={handleSubmit} className="max-w-3xl space-y-6 pb-20">
                {/* Gateway Default */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-4 flex items-center gap-2">
                        <IconCash size={18} />
                        Gateway Default
                    </h3>
                    <div>
                        <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                            Pilih Gateway Utama
                        </label>
                        <select
                            value={data.default_gateway}
                            onChange={(e) => setData("default_gateway", e.target.value)}
                            className="w-full h-11 px-4 text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:ring-primary-500 transition-all"
                        >
                            {supportedGateways.map((gw) => (
                                <option
                                    key={gw.value}
                                    value={gw.value}
                                    disabled={!isGatewaySelectable(gw.value)}
                                >
                                    {gw.label} {!isGatewaySelectable(gw.value) && "(Nonaktif)"}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* QRIS MANUAL SECTION */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 ring-1 ring-primary-500/10 shadow-sm">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                            <IconQrcode size={20} className="text-primary-500" />
                            QRIS Manual (Gambar Statis)
                        </h3>
                        <label className={`flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all ${data.qris_manual_enabled ? "bg-success-100 text-success-700" : "bg-slate-100 text-slate-500"}`}>
                            <Checkbox
                                checked={data.qris_manual_enabled}
                                onChange={(e) => setData("qris_manual_enabled", e.target.checked)}
                            />
                            {data.qris_manual_enabled ? "Aktif" : "Nonaktif"}
                        </label>
                    </div>

                    <div className={`grid md:grid-cols-2 gap-6 ${!data.qris_manual_enabled && "opacity-40 pointer-events-none"}`}>
                        <div className="space-y-4">
                            <p className="text-xs text-slate-500">Unggah barcode QRIS toko Anda. Gambar ini akan muncul di layar kasir saat metode QRIS dipilih.</p>
                            <Input
                                label="Unggah QRIS (PNG/JPG)"
                                type="file"
                                onChange={handleImageChange}
                                accept="image/*"
                                errors={errors?.qris_manual_image}
                            />
                        </div>
                        <div className="flex flex-col items-center justify-center border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl p-4 bg-slate-50 dark:bg-slate-800/50">
                            <span className="text-[10px] font-bold text-slate-400 uppercase mb-2">Preview QRIS</span>
                            {qrisPreview ? (
                                <img src={qrisPreview} className="h-40 w-40 object-contain shadow-lg rounded-lg bg-white p-2" alt="QRIS" />
                            ) : (
                                <IconPhoto size={48} className="text-slate-300" />
                            )}
                        </div>
                    </div>
                </div>

                {/* Midtrans */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                            <IconBrandStripe size={18} />
                            Midtrans Snap
                        </h3>
                        <Checkbox
                            checked={data.midtrans_enabled}
                            onChange={(e) => setData("midtrans_enabled", e.target.checked)}
                        />
                    </div>
                    <div className={`space-y-4 ${!data.midtrans_enabled && "opacity-50 pointer-events-none"}`}>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Server Key"
                                value={data.midtrans_server_key}
                                onChange={(e) => setData("midtrans_server_key", e.target.value)}
                                errors={errors?.midtrans_server_key}
                            />
                            <Input
                                label="Client Key"
                                value={data.midtrans_client_key}
                                onChange={(e) => setData("midtrans_client_key", e.target.value)}
                                errors={errors?.midtrans_client_key}
                            />
                        </div>
                    </div>
                </div>

                {/* Submit */}
                <div className="flex justify-end">
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center gap-2 px-8 py-3 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-bold transition-all shadow-lg shadow-primary-500/25 disabled:opacity-50"
                    >
                        <IconDeviceFloppy size={20} />
                        {processing ? "Menyimpan..." : "Simpan Konfigurasi"}
                    </button>
                </div>
            </form>
        </>
    );
}

Payment.layout = (page) => <DashboardLayout children={page} />;