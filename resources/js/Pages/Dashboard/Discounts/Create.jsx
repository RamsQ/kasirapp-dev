import React from "react";
import { Head, useForm, Link } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconDeviceFloppy, IconArrowLeft, IconTicket, IconPackage, IconGift } from "@tabler/icons-react";
import Swal from "sweetalert2";

export default function Create({ products }) {
    const { data, setData, post, processing, errors } = useForm({
        name: "",
        type: "percentage", // percentage, fixed, buy_get
        value: "",
        product_id: "",      // Produk yang harus dibeli (Target)
        bonus_product_id: "", // Produk gratis (Bonus) - Khusus tipe Buy X Get Y
        min_transaction: 0,
        start_date: new Date().toISOString().split("T")[0],
        end_date: "",
        description: ""
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("discounts.store"), {
            onSuccess: () => {
                Swal.fire("Berhasil!", "Promo diskon berhasil dibuat.", "success");
            }
        });
    };

    return (
        <>
            <Head title="Buat Promo Baru" />
            <div className="max-w-3xl mx-auto space-y-6">
                <div className="flex items-center justify-between">
                    <Link href={route('discounts.index')} className="text-slate-500 hover:text-primary-500 flex items-center gap-2 transition-colors">
                        <IconArrowLeft size={20}/> <span className="font-medium">Kembali</span>
                    </Link>
                </div>
                
                <div className="bg-white dark:bg-slate-900 p-8 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl">
                    <div className="flex items-center gap-3 mb-6 pb-6 border-b border-slate-100 dark:border-slate-800">
                        <div className="p-3 bg-primary-100 dark:bg-primary-900 text-primary-600 rounded-xl">
                            <IconTicket size={28} />
                        </div>
                        <div>
                            <h1 className="text-xl font-bold text-slate-900 dark:text-white">Buat Promo Baru</h1>
                            <p className="text-sm text-slate-500">Isi detail promo diskon di bawah ini.</p>
                        </div>
                    </div>
                    
                    <form onSubmit={submit} className="space-y-6">
                        {/* Nama Promo */}
                        <div>
                            <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Nama Promo</label>
                            <input 
                                type="text" 
                                value={data.name} 
                                onChange={e => setData('name', e.target.value)} 
                                placeholder="Contoh: Promo Beli 1 Gratis 1 / Diskon Gajian" 
                                className="w-full px-4 py-3 rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white focus:ring-primary-500 focus:border-primary-500 transition-all" 
                            />
                            {errors.name && <div className="text-red-500 text-xs mt-1 font-medium">{errors.name}</div>}
                        </div>

                        {/* Tipe Diskon */}
                        <div>
                            <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Tipe Promo</label>
                            <div className="grid grid-cols-3 gap-4">
                                <button
                                    type="button"
                                    onClick={() => setData('type', 'percentage')}
                                    className={`p-3 rounded-xl border text-sm font-semibold transition-all ${data.type === 'percentage' ? 'bg-primary-50 border-primary-500 text-primary-600' : 'border-slate-200 text-slate-500'}`}
                                >
                                    Persentase (%)
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setData('type', 'fixed')}
                                    className={`p-3 rounded-xl border text-sm font-semibold transition-all ${data.type === 'fixed' ? 'bg-primary-50 border-primary-500 text-primary-600' : 'border-slate-200 text-slate-500'}`}
                                >
                                    Nominal (Rp)
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setData('type', 'buy_get')}
                                    className={`p-3 rounded-xl border text-sm font-semibold transition-all ${data.type === 'buy_get' ? 'bg-emerald-50 border-emerald-500 text-emerald-600' : 'border-slate-200 text-slate-500'}`}
                                >
                                    Beli X Gratis Y
                                </button>
                            </div>
                        </div>

                        {/* Kondisi Berdasarkan Tipe */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-100 dark:border-slate-800">
                            {/* Input Nilai (Hanya untuk tipe diskon biasa) */}
                            {data.type !== 'buy_get' ? (
                                <div className="md:col-span-2">
                                    <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                                        Besar Potongan {data.type === 'percentage' ? '(%)' : '(Rp)'}
                                    </label>
                                    <input 
                                        type="number" 
                                        value={data.value} 
                                        onChange={e => setData('value', e.target.value)} 
                                        placeholder={data.type === 'percentage' ? 'Contoh: 10' : 'Contoh: 5000'} 
                                        className="w-full px-4 py-3 rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white focus:ring-primary-500" 
                                    />
                                    {errors.value && <div className="text-red-500 text-xs mt-1 font-medium">{errors.value}</div>}
                                </div>
                            ) : (
                                <div className="md:col-span-2 text-emerald-600 text-xs font-medium mb-2 flex items-center gap-1">
                                    <IconGift size={14}/> Sistem akan memberikan produk bonus secara gratis saat syarat produk utama terpenuhi.
                                </div>
                            )}

                            {/* Produk Utama (Target) */}
                            <div className={data.type === 'buy_get' ? 'md:col-span-1' : 'md:col-span-2'}>
                                <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2 flex items-center gap-2">
                                    <IconPackage size={18} className="text-slate-400"/> {data.type === 'buy_get' ? 'Produk yang Dibeli' : 'Target Diskon'}
                                </label>
                                <select 
                                    value={data.product_id} 
                                    onChange={e => setData('product_id', e.target.value)} 
                                    className="w-full px-4 py-3 rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white focus:ring-primary-500 cursor-pointer"
                                >
                                    <option value="">{data.type === 'buy_get' ? '-- Pilih Produk --' : 'Semua Produk (Global)'}</option>
                                    {products && products.map((p) => (
                                        <option key={p.id} value={p.id}>{p.title}</option>
                                    ))}
                                </select>
                            </div>

                            {/* Produk Bonus (Hanya tampil jika tipe Buy X Get Y) */}
                            {data.type === 'buy_get' && (
                                <div className="md:col-span-1">
                                    <label className="block text-sm font-bold text-emerald-700 dark:text-emerald-400 mb-2 flex items-center gap-2">
                                        <IconGift size={18} className="text-emerald-500"/> Produk Gratis (Bonus)
                                    </label>
                                    <select 
                                        value={data.bonus_product_id} 
                                        onChange={e => setData('bonus_product_id', e.target.value)} 
                                        className="w-full px-4 py-3 rounded-xl border-emerald-200 dark:border-emerald-900/50 dark:bg-slate-800 dark:text-white focus:ring-emerald-500 cursor-pointer"
                                    >
                                        <option value="">-- Pilih Produk Gratis --</option>
                                        {products && products.map((p) => (
                                            <option key={p.id} value={p.id}>{p.title}</option>
                                        ))}
                                    </select>
                                    {errors.bonus_product_id && <div className="text-red-500 text-xs mt-1 font-medium">{errors.bonus_product_id}</div>}
                                </div>
                            )}
                        </div>

                        {/* Minimal Belanja */}
                        <div>
                            <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                                {data.type === 'buy_get' ? 'Minimal Pembelian Produk Tersebut (Qty)' : 'Minimal Total Belanja (Rp)'}
                            </label>
                            <input 
                                type="number" 
                                value={data.min_transaction} 
                                onChange={e => setData('min_transaction', e.target.value)} 
                                className="w-full px-4 py-3 rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white focus:ring-primary-500" 
                            />
                            <p className="text-[11px] text-slate-500 mt-2">
                                {data.type === 'buy_get' 
                                    ? `*Contoh: Jika diisi 2, maka beli 2 baru dapat gratis 1 bonus.`
                                    : `*Isi 0 jika promo ini berlaku tanpa syarat minimal.`}
                            </p>
                        </div>

                        {/* Periode */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Tanggal Mulai</label>
                                <input 
                                    type="date" 
                                    value={data.start_date} 
                                    onChange={e => setData('start_date', e.target.value)} 
                                    className="w-full px-4 py-3 rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white focus:ring-primary-500" 
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Tanggal Selesai</label>
                                <input 
                                    type="date" 
                                    value={data.end_date} 
                                    onChange={e => setData('end_date', e.target.value)} 
                                    className="w-full px-4 py-3 rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white focus:ring-primary-500" 
                                />
                                {errors.end_date && <div className="text-red-500 text-xs mt-1 font-medium">{errors.end_date}</div>}
                            </div>
                        </div>

                        <div className="pt-6 flex justify-end">
                            <button 
                                type="submit" 
                                disabled={processing} 
                                className="bg-primary-500 text-white px-8 py-3 rounded-xl flex items-center gap-2 hover:bg-primary-600 shadow-lg shadow-primary-500/30 transition-all font-semibold disabled:opacity-70 disabled:cursor-not-allowed"
                            >
                                <IconDeviceFloppy size={20} />
                                {processing ? 'Menyimpan...' : 'Simpan Promo'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;