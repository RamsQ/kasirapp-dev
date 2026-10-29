import React from "react";
import { Head, useForm, Link } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { 
    IconSettings, IconInfoCircle, IconRefresh, 
    IconDatabase, IconTruckDelivery, IconHistory, 
    IconBluetooth, IconChevronRight, IconShieldLock, 
    IconLock, IconCheck
} from "@tabler/icons-react";
import Swal from "sweetalert2";

const SettingIndex = ({ auth, settings, canManageCgos }) => {
    // Form handling menggunakan Inertia useForm
    const { data, setData, post, processing, errors } = useForm({
        cogs_method: settings?.cogs_method || 'AVERAGE',
        tax_enabled: settings?.tax_enabled === true || settings?.tax_enabled === 1 || settings?.tax_enabled === '1',
        tax_rate: settings?.tax_rate ?? '',
        loyalty_enabled: settings?.loyalty_enabled === true || settings?.loyalty_enabled === 1 || settings?.loyalty_enabled === '1',
        loyalty_points_per_100: settings?.loyalty_points_per_100 ?? 1,
        kitchen_display_enabled: settings?.kitchen_display_enabled === true || settings?.kitchen_display_enabled === 1 || settings?.kitchen_display_enabled === '1',
        kitchen_display_payment_status: settings?.kitchen_display_payment_status || 'both',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('settings.update'), {
            preserveScroll: true,
            onSuccess: () => {
                Swal.fire({
                    title: 'Berhasil!',
                    text: 'Metode HPP, pajak, loyalty, dan Kitchen Display telah diperbarui.',
                    icon: 'success',
                    showConfirmButton: false,
                    timer: 1500,
                    customClass: {
                        popup: 'rounded-3xl dark:bg-slate-900 dark:text-white',
                    }
                });
            },
        });
    };

    const methods = [
        { 
            id: 'AVERAGE', 
            title: 'Moving Average', 
            icon: <IconRefresh size={24} />, 
            color: 'text-blue-500',
            desc: 'Harga modal dirata-rata setiap ada stok baru masuk. Paling umum digunakan retail.' 
        },
        { 
            id: 'FIFO', 
            title: 'FIFO (First-In First-Out)', 
            icon: <IconTruckDelivery size={24} />, 
            color: 'text-emerald-500',
            desc: 'Barang yang pertama kali dibeli adalah yang pertama kali dijual. Bagus untuk produk expired.' 
        },
        { 
            id: 'LIFO', 
            title: 'LIFO (Last-In First-Out)', 
            icon: <IconHistory size={24} />, 
            color: 'text-orange-500',
            desc: 'Barang yang terakhir masuk dianggap terjual lebih dulu. Jarang digunakan, sesuai kebijakan akuntansi.' 
        },
    ];

    return (
        <>
            <Head title="System Settings" />
            <div className="max-w-5xl flex flex-col gap-8 pb-20">
                
                {/* 1. HEADER HALAMAN */}
                <div className="flex items-center gap-4">
                    <div className="p-3 bg-slate-900 dark:bg-slate-800 rounded-2xl text-white shadow-xl">
                        <IconSettings size={28} />
                    </div>
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white uppercase tracking-tighter italic">Settings</h1>
                        <p className="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-[0.2em]">Konfigurasi Perangkat & Inti Aplikasi</p>
                    </div>
                </div>

                {/* 2. SEKSI HARDWARE (BLUETOOTH) - TERBUKA UNTUK SEMUA */}
                <div className="bg-white dark:bg-slate-900 p-8 rounded-[3rem] shadow-sm border border-slate-100 dark:border-slate-800 group hover:border-indigo-500/30 transition-all duration-500">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div className="flex items-center gap-5">
                            <div className="w-16 h-16 bg-indigo-50 dark:bg-indigo-950/30 text-indigo-600 rounded-[1.5rem] flex items-center justify-center shadow-inner">
                                <IconBluetooth size={32} />
                            </div>
                            <div>
                                <h3 className="text-lg font-black uppercase tracking-tighter dark:text-white leading-none">Printer Bluetooth</h3>
                                <p className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mt-2">Hubungkan kasir dengan Printer Thermal</p>
                            </div>
                        </div>
                        <Link 
                            href={route('settings.bluetooth')}
                            className="flex items-center justify-center gap-3 px-8 py-4 bg-indigo-600 text-white rounded-2xl font-black text-xs uppercase tracking-widest hover:bg-indigo-700 transition-all shadow-xl shadow-indigo-500/20 active:scale-95"
                        >
                            Terminal Pairing <IconChevronRight size={18} />
                        </Link>
                    </div>
                </div>

                {/* 3. SEKSI SYSTEM CORE (CGOS) - BERBASIS IZIN */}
                {canManageCgos ? (
                    <form onSubmit={submit} className="space-y-6 animate-in fade-in zoom-in duration-700">
                        <div className="bg-white dark:bg-slate-900 rounded-[3.5rem] border-2 border-dashed border-rose-200 dark:border-rose-900/30 overflow-hidden relative">
                            {/* Header Form CGOS */}
                            <div className="bg-rose-600 p-8 text-white flex items-center justify-between">
                                <div className="flex items-center gap-4">
                                    <IconShieldLock size={32} />
                                    <div>
                                        <h3 className="text-xl font-black uppercase italic tracking-tighter leading-none">System Core (CGOS)</h3>
                                        <p className="text-[10px] font-bold uppercase opacity-80 tracking-widest mt-1">Metode Akuntansi & Inventori</p>
                                    </div>
                                </div>
                                <div className="hidden sm:block px-4 py-1 bg-white/20 rounded-full text-[9px] font-black uppercase tracking-widest backdrop-blur-md border border-white/20">Authorized</div>
                            </div>

                            <div className="p-10">
                                <div className="flex items-center gap-2 mb-8">
                                    <IconDatabase className="text-rose-600" size={20} />
                                    <h3 className="font-black text-xs uppercase tracking-widest text-slate-800 dark:text-slate-200">
                                        Pilih Metode HPP (COGS)
                                    </h3>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    {methods.map((method) => (
                                        <button
                                            key={method.id}
                                            type="button"
                                            onClick={() => setData('cogs_method', method.id)}
                                            className={`flex flex-col p-6 rounded-[2rem] border-2 text-left transition-all duration-300 ${
                                                data.cogs_method === method.id 
                                                ? 'border-rose-600 bg-rose-50 dark:bg-rose-950/20' 
                                                : 'border-slate-100 dark:border-slate-800 hover:border-slate-200 dark:hover:border-slate-700'
                                            }`}
                                        >
                                            <div className={`mb-4 ${method.color}`}>
                                                {method.icon}
                                            </div>
                                            <div className="flex items-center justify-between mb-2">
                                                <span className="font-black text-sm uppercase dark:text-white tracking-tight">{method.title}</span>
                                                {data.cogs_method === method.id && (
                                                    <div className="w-5 h-5 rounded-full bg-rose-600 flex items-center justify-center">
                                                        <IconCheck size={12} className="text-white" />
                                                    </div>
                                                )}
                                            </div>
                                            <p className="text-[10px] text-slate-500 dark:text-slate-400 font-bold leading-relaxed">
                                                {method.desc}
                                            </p>
                                        </button>
                                    ))}
                                </div>

                                <div className="mt-8 p-6 bg-indigo-50 dark:bg-indigo-950/20 rounded-[2rem] border border-indigo-100 dark:border-indigo-900/30">
                                    <div className="flex items-start justify-between gap-6">
                                        <div>
                                            <h3 className="font-black text-sm uppercase tracking-wider text-indigo-900 dark:text-indigo-200">Pajak Transaksi</h3>
                                            <p className="mt-1 text-[10px] font-bold text-indigo-700 dark:text-indigo-300 leading-relaxed">
                                                Opsional. Jika aktif, pajak dihitung setelah diskon dan ditambahkan ke total pembayaran. Pajak dicatat terpisah dan tidak otomatis dianggap sebagai beban.
                                            </p>
                                        </div>
                                        <label className="inline-flex items-center cursor-pointer shrink-0">
                                            <input
                                                type="checkbox"
                                                checked={data.tax_enabled}
                                                onChange={(e) => setData('tax_enabled', e.target.checked)}
                                                className="sr-only peer"
                                            />
                                            <span className="w-12 h-6 rounded-full bg-slate-300 dark:bg-slate-700 peer-checked:bg-indigo-600 relative transition-all after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-6"></span>
                                        </label>
                                    </div>

                                    {data.tax_enabled && (
                                        <div className="mt-5">
                                            <label htmlFor="tax_rate" className="block text-xs font-black text-slate-700 dark:text-slate-200 mb-2">
                                                Persentase pajak (%)
                                            </label>
                                            <input
                                                id="tax_rate"
                                                type="number"
                                                min="0.01"
                                                max="100"
                                                step="0.01"
                                                value={data.tax_rate}
                                                onChange={(e) => setData('tax_rate', e.target.value)}
                                                placeholder="Contoh: 11"
                                                className="w-full max-w-xs h-11 px-4 rounded-xl border border-indigo-200 dark:border-indigo-900 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                                            />
                                            {errors.tax_rate && (
                                                <p className="mt-2 text-[10px] font-bold text-rose-500">{errors.tax_rate}</p>
                                            )}
                                        </div>
                                    )}
                                </div>

                                <div className="mt-8 p-6 bg-emerald-50 dark:bg-emerald-950/20 rounded-[2rem] border border-emerald-100 dark:border-emerald-900/30">
                                    <div className="flex items-start justify-between gap-6">
                                        <div>
                                            <h3 className="font-black text-sm uppercase tracking-wider text-emerald-900 dark:text-emerald-200">Loyalty & CRM</h3>
                                            <p className="mt-1 text-[10px] font-bold text-emerald-700 dark:text-emerald-300 leading-relaxed">
                                                Berikan poin dari omzet bersih pelanggan yang sudah lunas. Poin tidak memengaruhi perhitungan laba rugi.
                                            </p>
                                        </div>
                                        <label className="inline-flex items-center cursor-pointer shrink-0">
                                            <input type="checkbox" checked={data.loyalty_enabled} onChange={(e) => setData('loyalty_enabled', e.target.checked)} className="sr-only peer" />
                                            <span className="w-12 h-6 rounded-full bg-slate-300 dark:bg-slate-700 peer-checked:bg-emerald-600 relative transition-all after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-6"></span>
                                        </label>
                                    </div>
                                    {data.loyalty_enabled && (
                                        <div className="mt-5 max-w-xs">
                                            <label htmlFor="loyalty_points_per_100" className="block text-xs font-black text-slate-700 dark:text-slate-200 mb-2">Poin per Rp100 omzet bersih</label>
                                            <input id="loyalty_points_per_100" type="number" min="0" max="10000" value={data.loyalty_points_per_100} onChange={(e) => setData('loyalty_points_per_100', e.target.value)} className="w-full h-11 px-4 rounded-xl border border-emerald-200 dark:border-emerald-900 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-emerald-500/20" />
                                            {errors.loyalty_points_per_100 && <p className="mt-2 text-[10px] font-bold text-rose-500">{errors.loyalty_points_per_100}</p>}
                                        </div>
                                    )}
                                </div>

                                <div className="mt-8 p-6 bg-orange-50 dark:bg-orange-950/20 rounded-[2rem] border border-orange-100 dark:border-orange-900/30">
                                    <div className="flex items-start justify-between gap-6">
                                        <div>
                                            <h3 className="font-black text-sm uppercase tracking-wider text-orange-900 dark:text-orange-200">Kitchen Display</h3>
                                            <p className="mt-1 text-[10px] font-bold text-orange-700 dark:text-orange-300 leading-relaxed">
                                                Tampilkan pesanan aktif di layar dapur. Kitchen Display akan memperbarui otomatis setiap beberapa detik.
                                            </p>
                                        </div>
                                        <label className="inline-flex items-center cursor-pointer shrink-0">
                                            <input
                                                type="checkbox"
                                                checked={data.kitchen_display_enabled}
                                                onChange={(e) => setData('kitchen_display_enabled', e.target.checked)}
                                                className="sr-only peer"
                                            />
                                            <span className="w-12 h-6 rounded-full bg-slate-300 dark:bg-slate-700 peer-checked:bg-orange-600 relative transition-all after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-6"></span>
                                        </label>
                                    </div>

                                    {data.kitchen_display_enabled && (
                                        <div className="mt-5">
                                            <label htmlFor="kitchen_display_payment_status" className="block text-xs font-black text-slate-700 dark:text-slate-200 mb-2">
                                                Status pembayaran yang ditampilkan
                                            </label>
                                            <select
                                                id="kitchen_display_payment_status"
                                                value={data.kitchen_display_payment_status}
                                                onChange={(e) => setData('kitchen_display_payment_status', e.target.value)}
                                                className="w-full max-w-sm h-11 px-4 rounded-xl border border-orange-200 dark:border-orange-900 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500"
                                            >
                                                <option value="unpaid">Belum dibayar</option>
                                                <option value="paid">Sudah dibayar</option>
                                                <option value="both">Semua status</option>
                                            </select>
                                            <p className="mt-2 text-[10px] font-bold text-orange-700 dark:text-orange-300">
                                                Pesanan publik tetap berstatus belum dibayar sampai checkout kasir.
                                            </p>
                                            {errors.kitchen_display_payment_status && (
                                                <p className="mt-2 text-[10px] font-bold text-rose-500">{errors.kitchen_display_payment_status}</p>
                                            )}
                                        </div>
                                    )}
                                </div>

                                <div className="mt-8 p-6 bg-amber-50 dark:bg-amber-900/10 rounded-[2rem] flex gap-4 border border-amber-100 dark:border-amber-900/20">
                                    <IconInfoCircle className="text-amber-600 shrink-0" size={24} />
                                    <div className="space-y-1">
                                        <p className="text-xs font-black text-amber-800 dark:text-amber-400 uppercase tracking-tight">Perhatian Administrator</p>
                                        <p className="text-[10px] text-amber-700 dark:text-amber-500 leading-normal font-bold">
                                            Perubahan metode HPP akan mempengaruhi laporan laba rugi secara global. Jika Anda mengganti metode saat stok masih tersedia, sistem akan menerapkan logika baru pada transaksi berikutnya.
                                        </p>
                                    </div>
                                </div>
                                
                                <div className="flex justify-end mt-10">
                                    <button 
                                        disabled={processing}
                                        type="submit"
                                        className="bg-rose-600 text-white font-black text-xs px-10 py-4 rounded-2xl uppercase tracking-[0.2em] shadow-xl shadow-rose-600/20 active:scale-95 transition-all disabled:opacity-50 flex items-center gap-2"
                                    >
                                        {processing ? 'Menyimpan...' : 'Update System Core'} <IconCheck size={18} />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                ) : (
                    /* 4. STATE TERKUNCI (LOCK) - JIKA TIDAK PUNYA PERMISSION */
                    <div className="p-16 bg-slate-100 dark:bg-slate-900/50 rounded-[3.5rem] border border-dashed border-slate-200 dark:border-slate-800 flex flex-col items-center text-center">
                        <div className="w-24 h-24 bg-slate-200 dark:bg-slate-800 rounded-full flex items-center justify-center text-slate-500 dark:text-slate-400 mb-6 shadow-inner">
                            <IconLock size={48} />
                        </div>
                        <h4 className="text-xl font-black uppercase italic text-slate-500 dark:text-slate-400 tracking-tighter">System Core Restricted</h4>
                        <p className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400 mt-4 max-w-sm leading-relaxed">
                            Pengaturan tingkat lanjut (CGOS) sedang dikunci. Silakan hubungi Super Admin untuk mendapatkan akses izin settings sistem.
                        </p>
                    </div>
                )}
            </div>
        </>
    );
};

SettingIndex.layout = (page) => <DashboardLayout children={page} />;
export default SettingIndex;
