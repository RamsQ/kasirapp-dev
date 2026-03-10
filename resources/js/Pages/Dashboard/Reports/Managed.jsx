import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import Layout from '@/Layouts/DashboardLayout';
import { 
    IconBellRinging, 
    IconDeviceFloppy, 
    IconMail, 
    IconBrandWhatsapp, 
    IconClock, 
    IconSettings,
    IconInfoCircle,
    IconCalendarStats,
    IconCalendarMonth,
    IconFileText
} from '@tabler/icons-react';
import toast from 'react-hot-toast';

export default function Managed({ setting }) {
    // Inisialisasi form dengan data dari database (termasuk kolom baru)
    const { data, setData, post, processing } = useForm({
        is_active: setting?.is_active ?? false,
        is_weekly: setting?.is_weekly ?? false,
        is_monthly: setting?.is_monthly ?? false,
        method: setting?.method ?? 'whatsapp',
        target: setting?.target ?? '',
        send_at: setting?.send_at ?? '21:00',
        wa_api_key: setting?.wa_api_key ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('report-settings.store'), {
            onSuccess: () => toast.success('Pengaturan laporan berhasil diperbarui!', {
                style: {
                    borderRadius: '10px',
                    background: '#333',
                    color: '#fff',
                },
            }),
        });
    };

    return (
        <Layout>
            <Head title="Managed Reports - POS System" />
            
            <div className="p-4 md:p-8">
                <div className="max-w-4xl mx-auto">
                    {/* Header Card */}
                    <div className="bg-white dark:bg-slate-900 rounded-[2rem] p-8 shadow-sm border dark:border-slate-800 mb-6 relative overflow-hidden">
                        <div className="absolute top-0 right-0 p-8 opacity-5">
                            <IconSettings size={120} />
                        </div>
                        
                        <div className="relative z-10">
                            <div className="flex items-center gap-4 mb-4">
                                <div className="p-3 bg-primary-500 text-white rounded-2xl shadow-lg shadow-primary-500/20">
                                    <IconBellRinging size={24} />
                                </div>
                                <div>
                                    <h2 className="text-2xl font-black uppercase italic dark:text-white tracking-tighter">Managed Reports</h2>
                                    <p className="text-[10px] text-slate-500 uppercase font-black tracking-[0.2em]">Otomatisasi Laporan Penjualan</p>
                                </div>
                            </div>
                            <p className="text-sm text-slate-600 dark:text-slate-400 max-w-md">
                                Kelola siklus pengiriman laporan otomatis untuk memantau performa bisnis Anda secara berkala.
                            </p>
                        </div>
                    </div>

                    <form onSubmit={submit} className="space-y-6">
                        {/* Section 1: Aktivasi & Siklus */}
                        <div className="bg-white dark:bg-slate-900 rounded-[2rem] p-8 shadow-sm border dark:border-slate-800">
                            <div className="flex items-center gap-2 mb-6 ml-2">
                                <IconCalendarStats size={20} className="text-primary-500" />
                                <h4 className="text-xs font-black uppercase dark:text-white tracking-widest">Konfigurasi Jadwal</h4>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                {/* Harian */}
                                <div className={`p-6 rounded-[2rem] border-2 transition-all ${data.is_active ? 'border-primary-500 bg-primary-50/50 dark:bg-primary-950/10' : 'border-slate-100 dark:border-slate-800 opacity-60'}`}>
                                    <div className="flex justify-between items-start mb-4">
                                        <div className="p-2 bg-white dark:bg-slate-800 rounded-xl shadow-sm">
                                            <IconClock size={20} className="text-primary-500" />
                                        </div>
                                        <input 
                                            type="checkbox" 
                                            checked={data.is_active}
                                            onChange={e => setData('is_active', e.target.checked)}
                                            className="w-5 h-5 rounded-full text-primary-600 focus:ring-primary-500 border-slate-300 cursor-pointer"
                                        />
                                    </div>
                                    <p className="text-[10px] font-black uppercase text-slate-400 mb-1">Setiap Hari</p>
                                    <h5 className="font-bold dark:text-white text-sm uppercase">Laporan Harian</h5>
                                </div>

                                {/* Mingguan */}
                                <div className={`p-6 rounded-[2rem] border-2 transition-all ${data.is_weekly ? 'border-primary-500 bg-primary-50/50 dark:bg-primary-950/10' : 'border-slate-100 dark:border-slate-800 opacity-60'}`}>
                                    <div className="flex justify-between items-start mb-4">
                                        <div className="p-2 bg-white dark:bg-slate-800 rounded-xl shadow-sm">
                                            <IconCalendarStats size={20} className="text-primary-500" />
                                        </div>
                                        <input 
                                            type="checkbox" 
                                            checked={data.is_weekly}
                                            onChange={e => setData('is_weekly', e.target.checked)}
                                            className="w-5 h-5 rounded-full text-primary-600 focus:ring-primary-500 border-slate-300 cursor-pointer"
                                        />
                                    </div>
                                    <p className="text-[10px] font-black uppercase text-slate-400 mb-1">Setiap Senin</p>
                                    <h5 className="font-bold dark:text-white text-sm uppercase">Laporan Mingguan</h5>
                                </div>

                                {/* Bulanan */}
                                <div className={`p-6 rounded-[2rem] border-2 transition-all ${data.is_monthly ? 'border-primary-500 bg-primary-50/50 dark:bg-primary-950/10' : 'border-slate-100 dark:border-slate-800 opacity-60'}`}>
                                    <div className="flex justify-between items-start mb-4">
                                        <div className="p-2 bg-white dark:bg-slate-800 rounded-xl shadow-sm">
                                            <IconCalendarMonth size={20} className="text-primary-500" />
                                        </div>
                                        <input 
                                            type="checkbox" 
                                            checked={data.is_monthly}
                                            onChange={e => setData('is_monthly', e.target.checked)}
                                            className="w-5 h-5 rounded-full text-primary-600 focus:ring-primary-500 border-slate-300 cursor-pointer"
                                        />
                                    </div>
                                    <p className="text-[10px] font-black uppercase text-slate-400 mb-1">Tanggal 1</p>
                                    <h5 className="font-bold dark:text-white text-sm uppercase">Rekap Bulanan (PDF)</h5>
                                </div>
                            </div>
                        </div>

                        {/* Section 2: Metode & Destinasi */}
                        <div className="bg-white dark:bg-slate-900 rounded-[2rem] p-8 shadow-sm border dark:border-slate-800">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div className="space-y-6">
                                    <div>
                                        <label className="text-[10px] font-black uppercase text-slate-400 ml-4 mb-3 block tracking-widest leading-none">Metode Default</label>
                                        <div className="grid grid-cols-2 gap-3">
                                            <button 
                                                type="button" 
                                                onClick={() => setData('method', 'whatsapp')} 
                                                className={`p-4 rounded-2xl border-2 transition-all flex flex-col items-center gap-2 ${data.method === 'whatsapp' ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/20 text-primary-600' : 'border-slate-100 dark:border-slate-800 text-slate-400 hover:border-slate-200'}`}
                                            >
                                                <IconBrandWhatsapp size={28} />
                                                <span className="text-[10px] font-black uppercase">WhatsApp</span>
                                            </button>
                                            <button 
                                                type="button" 
                                                onClick={() => setData('method', 'email')} 
                                                className={`p-4 rounded-2xl border-2 transition-all flex flex-col items-center gap-2 ${data.method === 'email' ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/20 text-primary-600' : 'border-slate-100 dark:border-slate-800 text-slate-400 hover:border-slate-200'}`}
                                            >
                                                <IconMail size={28} />
                                                <span className="text-[10px] font-black uppercase">Email</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label className="text-[10px] font-black uppercase text-slate-400 ml-4 mb-2 block tracking-widest leading-none">Target Penerima</label>
                                        <div className="relative">
                                            <div className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                                {data.method === 'whatsapp' ? <IconBrandWhatsapp size={18} /> : <IconMail size={18} />}
                                            </div>
                                            <input 
                                                type="text" 
                                                value={data.target} 
                                                onChange={e => setData('target', e.target.value)} 
                                                className="w-full p-4 pl-12 bg-slate-50 dark:bg-slate-800 border-none rounded-2xl focus:ring-2 focus:ring-primary-500 dark:text-white font-bold transition-all shadow-inner" 
                                                placeholder={data.method === 'whatsapp' ? "08123456789" : "owner@email.com"}
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div className="space-y-6">
                                    <div>
                                        <label className="text-[10px] font-black uppercase text-slate-400 ml-4 mb-2 block tracking-widest leading-none">Waktu Kirim Harian/Mingguan</label>
                                        <div className="relative">
                                            <div className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                                <IconClock size={18} />
                                            </div>
                                            <input 
                                                type="time" 
                                                value={data.send_at} 
                                                onChange={e => setData('send_at', e.target.value)} 
                                                className="w-full p-4 pl-12 bg-slate-50 dark:bg-slate-800 border-none rounded-2xl focus:ring-2 focus:ring-primary-500 dark:text-white font-black text-lg transition-all shadow-inner" 
                                            />
                                        </div>
                                    </div>

                                    {data.method === 'whatsapp' && (
                                        <div>
                                            <label className="text-[10px] font-black uppercase text-slate-400 ml-4 mb-2 block tracking-widest leading-none flex items-center gap-1">
                                                API Key Fonnte <IconInfoCircle size={12} className="text-primary-500" />
                                            </label>
                                            <input 
                                                type="password" 
                                                value={data.wa_api_key} 
                                                onChange={e => setData('wa_api_key', e.target.value)} 
                                                className="w-full p-4 bg-slate-50 dark:bg-slate-800 border-none rounded-2xl focus:ring-2 focus:ring-primary-500 dark:text-white transition-all shadow-inner" 
                                                placeholder="Token Fonnte..."
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="mt-10">
                                <button 
                                    type="submit" 
                                    disabled={processing} 
                                    className="w-full py-5 bg-primary-600 hover:bg-primary-700 text-white rounded-[1.5rem] font-black uppercase shadow-xl shadow-primary-500/20 active:scale-95 transition-all flex items-center justify-center gap-3 tracking-[0.2em] text-xs disabled:opacity-50"
                                >
                                    {processing ? 'Menyimpan...' : <><IconDeviceFloppy size={20} /> Simpan Pengaturan</>}
                                </button>
                            </div>
                        </div>

                        {/* Tips Card */}
                        <div className="bg-blue-50 dark:bg-blue-900/10 rounded-3xl p-6 border border-blue-100 dark:border-blue-900 flex gap-4">
                            <div className="text-blue-500 shrink-0"><IconFileText /></div>
                            <div className="text-[10px] text-blue-700 dark:text-blue-400 leading-relaxed font-bold uppercase tracking-wider">
                                <strong>Info:</strong> Laporan bulanan akan otomatis dikirim dalam format PDF ke alamat email yang terdaftar, terlepas dari metode default yang dipilih.
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </Layout>
    );
}