import { useEffect } from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import { 
    IconLock, 
    IconMail, 
    IconRotateClockwise2, 
    IconLoader2,
    IconShieldCheck,
    IconArrowLeft
} from '@tabler/icons-react';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    useEffect(() => {
        return () => {
            reset('password', 'password_confirmation');
        };
    }, []);

    const submit = (e) => {
        e.preventDefault();
        post(route('password.store'));
    };

    return (
        <div className="min-h-screen flex bg-slate-50 dark:bg-slate-950 transition-colors duration-300 font-sans">
            <Head title="Atur Ulang Kata Sandi" />

            {/* SISI KIRI: FORM RESET */}
            <div className="flex-1 flex items-center justify-center p-6 sm:p-12 bg-white dark:bg-slate-900 shadow-xl z-10 transition-colors duration-300">
                <div className="w-full max-w-md">
                    
                    {/* Tombol Kembali */}
                    <div className="mb-8">
                        <Link 
                            href={route('login')} 
                            className="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 hover:text-primary-500 transition-colors group"
                        >
                            <IconArrowLeft size={16} className="group-hover:-translate-x-1 transition-transform" />
                            Batal & Kembali
                        </Link>
                    </div>

                    {/* Header Brand */}
                    <div className="mb-10 text-center lg:text-left">
                        <div className="flex items-center justify-center lg:justify-start gap-4 mb-4">
                            <div className="w-12 h-12 rounded-2xl bg-primary-500 flex items-center justify-center shadow-lg shadow-primary-500/20">
                                <IconRotateClockwise2 size={28} className="text-white" />
                            </div>
                            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight uppercase italic">
                                Reset Sandi
                            </h1>
                        </div>
                        <p className="text-slate-500 dark:text-slate-400 font-medium">
                            Silakan masukkan kata sandi baru untuk mengamankan kembali akun Anda.
                        </p>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        {/* Input Email (ReadOnly untuk keamanan) */}
                        <div>
                            <label className="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Email Konfirmasi</label>
                            <div className="relative group opacity-60">
                                <div className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                    <IconMail size={20} />
                                </div>
                                <input 
                                    type="email" 
                                    value={data.email} 
                                    readOnly
                                    className="w-full h-14 pl-12 pr-4 rounded-2xl border-2 border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800 dark:text-slate-400 cursor-not-allowed outline-none"
                                />
                            </div>
                        </div>

                        {/* Input Password Baru */}
                        <div>
                            <label className="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Kata Sandi Baru</label>
                            <div className="relative group">
                                <div className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-primary-500 transition-colors">
                                    <IconLock size={20} />
                                </div>
                                <input 
                                    type="password" 
                                    value={data.password} 
                                    onChange={e => setData('password', e.target.value)}
                                    placeholder="••••••••"
                                    className={`w-full h-14 pl-12 pr-4 rounded-2xl border-2 ${
                                        errors.password ? 'border-red-500 ring-red-500/10' : 'border-slate-100 dark:border-slate-800'
                                    } bg-slate-50 dark:bg-slate-800 dark:text-white outline-none focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10 transition-all`}
                                    required
                                    autoFocus
                                />
                            </div>
                            {errors.password && <p className="text-red-500 text-[10px] mt-2 font-black uppercase tracking-tight">{errors.password}</p>}
                        </div>

                        {/* Konfirmasi Password Baru */}
                        <div>
                            <label className="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Konfirmasi Kata Sandi</label>
                            <div className="relative group">
                                <div className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-primary-500 transition-colors">
                                    <IconShieldCheck size={20} />
                                </div>
                                <input 
                                    type="password" 
                                    value={data.password_confirmation} 
                                    onChange={e => setData('password_confirmation', e.target.value)}
                                    placeholder="••••••••"
                                    className={`w-full h-14 pl-12 pr-4 rounded-2xl border-2 ${
                                        errors.password_confirmation ? 'border-red-500 ring-red-500/10' : 'border-slate-100 dark:border-slate-800'
                                    } bg-slate-50 dark:bg-slate-800 dark:text-white outline-none focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10 transition-all`}
                                    required
                                />
                            </div>
                            {errors.password_confirmation && <p className="text-red-500 text-[10px] mt-2 font-black uppercase tracking-tight">{errors.password_confirmation}</p>}
                        </div>

                        {/* Tombol Simpan */}
                        <button 
                            type="submit" 
                            disabled={processing}
                            className="w-full h-14 bg-primary-600 text-white rounded-2xl font-black uppercase tracking-widest hover:bg-primary-700 disabled:opacity-50 transition-all shadow-lg shadow-primary-500/20 flex items-center justify-center gap-3 active:scale-[0.98]"
                        >
                            {processing ? (
                                <IconLoader2 className="animate-spin" size={24} />
                            ) : "Perbarui Kata Sandi"}
                        </button>
                    </form>

                    <div className="mt-12 text-center">
                        <p className="text-[10px] text-slate-300 dark:text-slate-600 font-black uppercase tracking-[0.3em]">
                            Security Layer Active • POS v2.4
                        </p>
                    </div>
                </div>
            </div>

            {/* SISI KANAN: VISUAL (KONSISTEN DENGAN LOGIN & FORGOT) */}
            <div className="hidden lg:flex flex-1 relative bg-slate-100 items-center justify-center overflow-hidden">
                <img 
                    src="https://images.unsplash.com/photo-1614064641938-3bbee52942c7?q=80&w=1920" 
                    alt="Reset Background" 
                    className="absolute inset-0 w-full h-full object-cover scale-105 opacity-80"
                />
                
                {/* Overlay Dark Elegant */}
                <div className="absolute inset-0 bg-gradient-to-br from-slate-900/90 via-primary-900/20 to-slate-900/90 mix-blend-multiply"></div>
                
                {/* Content Visual */}
                <div className="relative z-10 text-white p-16 max-w-2xl text-right">
                    <h2 className="text-6xl font-black uppercase leading-[1.1] tracking-tighter mb-8 italic drop-shadow-2xl">
                        Satu Langkah <br /> <span className="text-primary-500">Lagi Selesai.</span>
                    </h2>
                    <p className="text-lg font-medium text-white/90 leading-relaxed mb-10 max-w-lg ml-auto border-r-4 border-primary-500 pr-6 backdrop-blur-md bg-black/20 py-4 rounded-l-2xl shadow-2xl">
                        Keamanan data transaksi Anda dimulai dari kata sandi yang kuat. Gunakan kombinasi karakter yang unik.
                    </p>
                </div>

                {/* Dots Pattern */}
                <div className="absolute inset-0 opacity-[0.1]" style={{ backgroundImage: `radial-gradient(#fff 1px, transparent 0)`, backgroundSize: `40px 40px` }}></div>
            </div>
        </div>
    );
}