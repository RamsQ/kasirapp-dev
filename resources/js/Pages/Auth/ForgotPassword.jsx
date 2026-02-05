import { Head, Link, useForm } from "@inertiajs/react";
import { 
    IconMail, 
    IconArrowLeft, 
    IconCircleCheck, 
    IconLoader2,
    IconShieldLock
} from "@tabler/icons-react";

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({
        email: "",
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("password.email"));
    };

    return (
        <div className="min-h-screen flex bg-slate-50 dark:bg-slate-950 transition-colors duration-300 font-sans">
            <Head title="Pemulihan Kata Sandi" />

            {/* SISI KIRI: FORM RECOVERY */}
            <div className="flex-1 flex items-center justify-center p-6 sm:p-12 bg-white dark:bg-slate-900 shadow-xl z-10 transition-colors duration-300">
                <div className="w-full max-w-md">
                    
                    {/* Header Section */}
                    <div className="mb-10 text-center lg:text-left">
                        <Link 
                            href={route('login')} 
                            className="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 hover:text-primary-500 transition-colors mb-8 group"
                        >
                            <IconArrowLeft size={16} className="group-hover:-translate-x-1 transition-transform" />
                            Kembali Ke Login
                        </Link>
                        
                        <div className="flex items-center justify-center lg:justify-start gap-4 mb-4">
                            <div className="w-12 h-12 rounded-2xl bg-orange-500 flex items-center justify-center shadow-lg shadow-orange-500/20">
                                <IconShieldLock size={28} className="text-white" />
                            </div>
                            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight uppercase">
                                Lupa Sandi?
                            </h1>
                        </div>
                        <p className="text-slate-500 dark:text-slate-400 font-medium leading-relaxed">
                            Masukkan email terdaftar. Kami akan mengirimkan tautan verifikasi untuk membuat kata sandi baru Anda.
                        </p>
                    </div>

                    {/* Alert Status Sukses */}
                    {status && (
                        <div className="mb-8 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 border-2 border-emerald-100 dark:border-emerald-800 flex items-center gap-4 animate-slide-up">
                            <div className="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center shrink-0">
                                <IconCircleCheck size={24} className="text-white" />
                            </div>
                            <p className="text-sm font-black text-emerald-800 dark:text-emerald-400 uppercase tracking-tight leading-tight">
                                {status}
                            </p>
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-6">
                        {/* Input Email */}
                        <div>
                            <label className="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Email Terdaftar</label>
                            <div className="relative group">
                                <div className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-primary-500 transition-colors">
                                    <IconMail size={20} />
                                </div>
                                <input 
                                    type="email" 
                                    value={data.email} 
                                    onChange={e => setData('email', e.target.value)}
                                    placeholder="kasir@tokopos.com"
                                    className={`w-full h-14 pl-12 pr-4 rounded-2xl border-2 ${
                                        errors.email ? 'border-red-500 ring-red-500/10' : 'border-slate-100 dark:border-slate-800'
                                    } bg-slate-50 dark:bg-slate-800 dark:text-white outline-none focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10 transition-all`}
                                    required
                                />
                            </div>
                            {errors.email && <p className="text-red-500 text-[10px] mt-2 font-black uppercase tracking-tight">{errors.email}</p>}
                        </div>

                        {/* Tombol Kirim */}
                        <button 
                            type="submit" 
                            disabled={processing}
                            className="w-full h-14 bg-primary-600 text-white rounded-2xl font-black uppercase tracking-widest hover:bg-primary-700 disabled:opacity-50 transition-all shadow-lg shadow-primary-500/20 flex items-center justify-center gap-3 active:scale-[0.98]"
                        >
                            {processing ? (
                                <IconLoader2 className="animate-spin" size={24} />
                            ) : "Kirim Tautan Verifikasi"}
                        </button>
                    </form>

                    <div className="mt-12 text-center">
                        <p className="text-xs text-slate-300 dark:text-slate-600 font-bold uppercase tracking-widest italic">
                            Sistem Pemulihan Identitas Otomatis v1.2
                        </p>
                    </div>
                </div>
            </div>

            {/* SISI KANAN: VISUAL (KONSISTEN DENGAN LOGIN) */}
            <div className="hidden lg:flex flex-1 relative bg-slate-100 items-center justify-center overflow-hidden">
                <img 
                    src="https://images.unsplash.com/photo-1556740758-90de374c12ad?q=80&w=1920" 
                    alt="Recovery Background" 
                    className="absolute inset-0 w-full h-full object-cover scale-105 opacity-80"
                />
                
                {/* Overlay Orange/Primary Mix untuk membedakan dengan Login */}
                <div className="absolute inset-0 bg-gradient-to-br from-slate-900/80 via-orange-900/20 to-slate-900/80 mix-blend-multiply"></div>
                
                {/* Content Visual */}
                <div className="relative z-10 text-white p-16 max-w-2xl text-right">
                    <h2 className="text-6xl font-black uppercase leading-[1.1] tracking-tighter mb-8 italic drop-shadow-2xl">
                        Keamanan Adalah <br /> <span className="text-orange-500">Prioritas.</span>
                    </h2>
                    <p className="text-lg font-medium text-white/90 leading-relaxed mb-10 max-w-lg ml-auto border-r-4 border-orange-500 pr-6 backdrop-blur-sm bg-black/10 py-2 rounded-l-xl">
                        Kami melindungi akses data finansial Anda dengan enkripsi token sekali pakai untuk setiap permintaan pemulihan sandi.
                    </p>
                </div>

                {/* Pola Dots Background */}
                <div className="absolute inset-0 opacity-[0.1]" style={{ backgroundImage: `radial-gradient(#fff 1px, transparent 0)`, backgroundSize: `40px 40px` }}></div>
            </div>
        </div>
    );
}