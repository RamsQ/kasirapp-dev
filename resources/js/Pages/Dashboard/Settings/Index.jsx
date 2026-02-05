import React from "react";
import { Head, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { 
    IconSettings, IconInfoCircle, IconRefresh, 
    IconDatabase, IconTruckDelivery, IconHistory, IconBarcode 
} from "@tabler/icons-react";
import Swal from "sweetalert2";

const SettingIndex = ({ auth, settings }) => {
    // Form handling menggunakan Inertia useForm
    const { data, setData, post, processing } = useForm({
        cogs_method: settings?.cogs_method || 'AVERAGE',
    });

    const submit = (e) => {
        e.preventDefault();
        // FIX: Mengubah rute sesuai dengan nama di web.php (settings.update)
        post(route('settings.update'), {
            preserveScroll: true,
            onSuccess: () => {
                Swal.fire({
                    title: 'Berhasil!',
                    text: 'Metode perhitungan HPP telah diperbarui.',
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
        { 
            id: 'SPECIFIC', 
            title: 'Specific Identification', 
            icon: <IconBarcode size={24} />, 
            color: 'text-purple-500',
            desc: 'HPP dihitung berdasarkan unit spesifik yang discan (menggunakan IMEI/Serial Number).' 
        },
    ];

    return (
        <>
            <Head title="System Settings" />
            <div className="max-w-4xl space-y-6">
                <div className="flex items-center gap-3 border-b dark:border-slate-800 pb-4">
                    <div className="p-3 bg-primary-600 rounded-2xl text-white shadow-lg shadow-primary-600/20">
                        <IconSettings size={24} />
                    </div>
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white uppercase tracking-tight">System Settings</h1>
                        <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">Konfigurasi Inti Aplikasi POS</p>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-8 shadow-sm">
                        <div className="flex items-center gap-2 mb-8">
                            <IconDatabase className="text-primary-600" size={20} />
                            <h3 className="font-black text-xs uppercase tracking-widest text-slate-800 dark:text-slate-200">
                                Metode Inventori & HPP (COGS)
                            </h3>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {methods.map((method) => (
                                <button
                                    key={method.id}
                                    type="button"
                                    onClick={() => setData('cogs_method', method.id)}
                                    className={`flex flex-col p-6 rounded-[2rem] border-2 text-left transition-all duration-300 ${
                                        data.cogs_method === method.id 
                                        ? 'border-primary-600 bg-primary-50 dark:bg-primary-900/10' 
                                        : 'border-slate-100 dark:border-slate-800 hover:border-slate-200 dark:hover:border-slate-700'
                                    }`}
                                >
                                    <div className={`mb-4 ${method.color}`}>
                                        {method.icon}
                                    </div>
                                    <div className="flex items-center justify-between mb-2">
                                        <span className="font-black text-sm uppercase dark:text-white tracking-tight">{method.title}</span>
                                        {data.cogs_method === method.id && (
                                            <div className="w-4 h-4 rounded-full bg-primary-600 flex items-center justify-center">
                                                <div className="w-2 h-2 rounded-full bg-white" />
                                            </div>
                                        )}
                                    </div>
                                    <p className="text-[10px] text-slate-500 dark:text-slate-400 font-bold leading-relaxed">
                                        {method.desc}
                                    </p>
                                </button>
                            ))}
                        </div>

                        <div className="mt-8 p-5 bg-amber-50 dark:bg-amber-900/10 rounded-3xl flex gap-4 border border-amber-100 dark:border-amber-900/20">
                            <IconInfoCircle className="text-amber-600 shrink-0" size={22} />
                            <div className="space-y-1">
                                <p className="text-xs font-black text-amber-800 dark:text-amber-400 uppercase tracking-tight">Perhatian Penting</p>
                                <p className="text-[10px] text-amber-700 dark:text-amber-500 leading-normal font-bold">
                                    Metode ini akan menentukan bagaimana Laba Bersih Anda dihitung. Jika Anda mengganti metode saat stok masih tersedia, sistem akan mulai menerapkan logika baru pada transaksi berikutnya. Disarankan untuk melakukan Stock Opname setelah pergantian metode besar.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="flex justify-end">
                        <button 
                            disabled={processing}
                            type="submit"
                            className="bg-slate-900 dark:bg-primary-600 text-white font-black text-xs px-10 py-4 rounded-2xl uppercase tracking-widest shadow-xl shadow-slate-900/20 dark:shadow-primary-600/20 active:scale-95 transition-all disabled:opacity-50"
                        >
                            {processing ? 'Menyimpan...' : 'Simpan Perubahan'}
                        </button>
                    </div>
                </form>
            </div>
        </>
    );
};

SettingIndex.layout = (page) => <DashboardLayout children={page} />;
export default SettingIndex;