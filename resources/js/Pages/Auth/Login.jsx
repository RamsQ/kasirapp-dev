import { useEffect, useState, useRef } from "react";
import { Head, Link, useForm, router } from "@inertiajs/react";
import { 
    IconShoppingCart, 
    IconMail, 
    IconLock, 
    IconEye, 
    IconEyeOff, 
    IconLoader2,
    IconFaceId,
    IconX,
    IconAlertTriangle,
    IconSparkles,
    IconInfinity,
    IconCircleCheck
} from "@tabler/icons-react";
import * as faceapi from 'face-api.js';
import axios from 'axios';
import Swal from 'sweetalert2';

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: "",
        password: "",
        remember: false,
    });

    const [showPassword, setShowPassword] = useState(false);
    
    // --- STATE & REF UNTUK FACE ID ---
    const videoRef = useRef();
    const [modelsLoaded, setModelsLoaded] = useState(false);
    const [isScanning, setIsScanning] = useState(false);
    const [faceAuthLoading, setFaceAuthLoading] = useState(false);
    const [isFaceMandatory, setIsFaceMandatory] = useState(false);

    // 1. Load Model AI saat komponen dimount
    useEffect(() => {
        const loadModels = async () => {
            const MODEL_URL = '/models';
            try {
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
                ]);
                setModelsLoaded(true);
            } catch (error) {
                console.error("Gagal memuat model Face API:", error);
            }
        };
        loadModels();
        return () => reset("password");
    }, []);

    // --- Cek Status Mandatory & Ketersediaan Data Wajah ---
    const checkEmailStatus = async (email) => {
        if (!email.includes('@')) return;
        try {
            const res = await axios.post('/face-auth/fetch-user', { email });
            if (res.data.status === 'success') {
                const harusWajah = res.data.is_mandatory && res.data.face_data !== null;
                setIsFaceMandatory(harusWajah);
            } else {
                setIsFaceMandatory(false);
            }
        } catch (e) {
            setIsFaceMandatory(false);
        }
    };

    // 2. Fungsi Jalankan Login Wajah
    const handleFaceLogin = async () => {
        if (!data.email) {
            return Swal.fire({
                title: 'Email Kosong',
                text: 'Silakan masukkan email Anda terlebih dahulu untuk verifikasi wajah.',
                icon: 'warning',
                confirmButtonColor: '#4f46e5'
            });
        }

        setFaceAuthLoading(true);

        try {
            const res = await axios.post('/face-auth/fetch-user', { email: data.email });
            
            if (res.data.status === 'error') {
                setFaceAuthLoading(false);
                return Swal.fire('Gagal', res.data.message, 'error');
            }

            if (!res.data.face_data) {
                setFaceAuthLoading(false);
                return Swal.fire('Belum Terdaftar', 'Wajah Anda belum didaftarkan. Silakan login manual dulu.', 'info');
            }

            setIsScanning(true);
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { facingMode: "user", width: { ideal: 640 }, height: { ideal: 480 } } 
            });
            if (videoRef.current) {
                videoRef.current.srcObject = stream;
            }

            const savedDescriptor = new Float32Array(res.data.face_data);
            const faceMatcher = new faceapi.FaceMatcher(savedDescriptor, 0.6);

            const interval = setInterval(async () => {
                if (!videoRef.current) return;
                
                const detection = await faceapi.detectSingleFace(
                    videoRef.current, 
                    new faceapi.TinyFaceDetectorOptions()
                ).withFaceLandmarks().withFaceDescriptor();

                if (detection) {
                    const match = faceMatcher.findBestMatch(detection.descriptor);
                    if (match.label !== 'unknown') {
                        clearInterval(interval);
                        if (videoRef.current.srcObject) {
                            videoRef.current.srcObject.getTracks().forEach(track => track.stop());
                        }
                        const loginRes = await axios.post('/face-auth/login', { user_id: res.data.user_id });
                        if (loginRes.data.status === 'success') {
                            router.visit(loginRes.data.redirect);
                        }
                    }
                }
            }, 1000);

        } catch (error) {
            setFaceAuthLoading(false);
            setIsScanning(false);
            Swal.fire('Error', 'Kamera tidak diizinkan atau bermasalah.', 'error');
        }
    };

    // --- LOGIKA SUBMIT DENGAN DOUBLE PROTECTION ---
    const submit = async (e) => {
        e.preventDefault();
        try {
            const check = await axios.post('/face-auth/fetch-user', { email: data.email });
            if (check.data.status === 'success' && check.data.is_mandatory && check.data.face_data !== null) {
                setIsFaceMandatory(true);
                return Swal.fire({ title: 'Keamanan Aktif', text: 'Akun ini wajib menggunakan Face ID.', icon: 'error' });
            }
        } catch (err) {}

        post(route("login"), {
            onFinish: () => reset("password"),
        });
    };

    return (
        <div className="min-h-screen flex bg-white dark:bg-slate-950 transition-colors duration-300 font-sans overflow-hidden">
            <Head title="Login | POS System Aja" />
            
            {/* SISI KIRI: FORM LOGIN */}
            <div className="flex-1 flex flex-col items-center justify-center p-8 sm:p-16 bg-white dark:bg-slate-900 z-20 relative transition-colors duration-300">
                <div className="w-full max-w-[380px]">
                    <div className="mb-10 text-center lg:text-left">
                        <div className="flex items-center justify-center lg:justify-start gap-4 mb-8">
                            <Link href="/" className="flex items-center gap-3">
                                <div className="w-12 h-12 rounded-2xl bg-indigo-600 flex items-center justify-center shadow-lg shadow-indigo-500/20 group-hover:scale-105 transition-all">
                                    <IconShoppingCart size={28} className="text-white" />
                                </div>
                                <span className="text-2xl font-black tracking-tighter text-slate-900 dark:text-white uppercase leading-none">
                                    POS SYSTEM <br/> <span className="text-indigo-600 text-sm tracking-[0.3em]">AJA</span>
                                </span>
                            </Link>
                        </div>
                        <h1 className="text-4xl font-black text-slate-900 dark:text-white tracking-tighter leading-none uppercase italic">Welcome Back :)</h1>
                        <p className="mt-4 text-slate-500 dark:text-slate-400 font-medium text-sm">Kelola operasional toko Anda secara profesional dalam satu genggaman.</p>
                    </div>

                    {isScanning ? (
                        <div className="animate-in fade-in zoom-in-95 duration-300">
                            <div className="relative mx-auto w-full aspect-square max-w-[300px] bg-slate-100 rounded-[2.5rem] overflow-hidden border-4 border-indigo-500 shadow-2xl">
                                <video ref={videoRef} autoPlay muted className="w-full h-full object-cover scale-x-[-1]" />
                                <div className="absolute inset-x-0 bottom-6 flex justify-center">
                                    <div className="px-6 py-2 bg-indigo-600 text-white text-[10px] font-black uppercase rounded-full shadow-xl flex items-center gap-2">
                                        <IconLoader2 size={14} className="animate-spin" /> Memindai...
                                    </div>
                                </div>
                            </div>
                            <button onClick={() => setIsScanning(false)} className="w-full mt-6 py-4 bg-slate-100 text-slate-600 rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-red-50 hover:text-red-500 transition-all">Batalkan</button>
                        </div>
                    ) : (
                        <form onSubmit={submit} className="space-y-5">
                            <div>
                                <label className="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2 ml-1">Email Kasir</label>
                                <div className="relative group">
                                    <IconMail className="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-indigo-500 transition-colors" size={20} />
                                    <input 
                                        type="email" 
                                        value={data.email} 
                                        onChange={e => { setData('email', e.target.value); checkEmailStatus(e.target.value); }}
                                        placeholder="kasir@mangkujagad.com"
                                        className="w-full h-14 pl-14 pr-5 rounded-2xl border-2 border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800 dark:text-white outline-none focus:border-indigo-500 font-bold transition-all"
                                    />
                                    {data.email.includes('@') && !errors.email && <IconCircleCheck className="absolute right-5 top-1/2 -translate-y-1/2 text-emerald-500 animate-in fade-in" size={20} />}
                                </div>
                            </div>

                            <button 
                                type="button"
                                onClick={handleFaceLogin}
                                disabled={!modelsLoaded || faceAuthLoading}
                                className={`w-full h-15 border-2 ${isFaceMandatory ? 'bg-indigo-600 border-indigo-600 text-white shadow-xl' : 'border-indigo-600/20 text-indigo-600 hover:bg-indigo-50'} rounded-2xl font-black uppercase text-[11px] tracking-[0.15em] transition-all flex items-center justify-center gap-4 active:scale-95 disabled:opacity-50`}
                            >
                                {faceAuthLoading ? <IconLoader2 className="animate-spin" size={20} /> : <IconFaceId size={24} />}
                                {isFaceMandatory ? "WAJIB SCAN WAJAH" : "Masuk Dengan Wajah"}
                            </button>

                            {!isFaceMandatory && (
                                <div className="space-y-5 animate-in slide-in-from-top-2 duration-500">
                                    <div className="relative py-2 flex items-center">
                                        <div className="flex-grow border-t border-slate-100 dark:border-slate-800"></div>
                                        <span className="flex-shrink mx-4 text-[10px] uppercase font-black text-slate-300 tracking-widest">Atau Password</span>
                                        <div className="flex-grow border-t border-slate-100 dark:border-slate-800"></div>
                                    </div>
                                    <div className="relative group">
                                        <IconLock className="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-indigo-500 transition-colors" size={20} />
                                        <input type={showPassword ? "text" : "password"} value={data.password} onChange={e => setData('password', e.target.value)} placeholder="••••••••" className="w-full h-14 pl-14 pr-14 rounded-2xl border-2 border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800 dark:text-white outline-none focus:border-indigo-500 font-bold transition-all" />
                                        <button type="button" onClick={() => setShowPassword(!showPassword)} className="absolute right-5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-indigo-500 transition-colors">
                                            {showPassword ? <IconEyeOff size={20} /> : <IconEye size={20} />}
                                        </button>
                                    </div>
                                    
                                    <div className="flex items-center justify-between px-1">
                                        <label className="flex items-center gap-3 cursor-pointer group">
                                            <input type="checkbox" checked={data.remember} onChange={e => setData('remember', e.target.checked)} className="w-5 h-5 rounded-lg border-slate-200 dark:border-slate-800 text-indigo-600 focus:ring-indigo-500 transition-all cursor-pointer" />
                                            <span className="text-xs font-black text-slate-500 dark:text-slate-400 group-hover:text-indigo-500 uppercase tracking-tighter transition-colors">Ingat Saya</span>
                                        </label>
                                        
                                        {/* LINK LUPA PASSWORD DIKEMBALIKAN */}
                                        {canResetPassword && (
                                            <Link 
                                                href={route("password.request")} 
                                                className="text-xs font-black text-indigo-600 hover:text-indigo-700 uppercase tracking-tighter underline underline-offset-4 decoration-indigo-500/30 hover:decoration-indigo-500 transition-all"
                                            >
                                                Lupa Sandi?
                                            </Link>
                                        )}
                                    </div>

                                    <button type="submit" disabled={processing} className="w-full h-15 bg-indigo-600 text-white rounded-2xl font-black uppercase text-[11px] tracking-[0.2em] shadow-[0_10px_25px_rgba(79,70,229,0.4)] hover:bg-indigo-700 transition-all active:scale-95 disabled:opacity-50">Masuk Ke Dashboard</button>
                                </div>
                            )}
                        </form>
                    )}
                </div>
                <div className="absolute bottom-8 text-[9px] font-black text-slate-300 dark:text-slate-700 uppercase tracking-[0.3em]">© 2026 POS SYSTEM AJA • MANGKUJAGAD TECH</div>
            </div>

            {/* SISI KANAN: ANIME SCENERY + MODERN ORGANIC CURVE */}
            <div className="hidden lg:flex flex-[1.4] relative bg-slate-900 items-center justify-center overflow-hidden">
                
                {/* --- MODERN ORGANIC CURVE SEPARATOR --- */}
                <div className="absolute left-0 top-0 bottom-0 w-[150px] z-10 pointer-events-none">
                    <svg className="h-full w-full fill-white dark:fill-slate-900 drop-shadow-[-15px_0_20px_rgba(0,0,0,0.1)]" viewBox="0 0 100 100" preserveAspectRatio="none">
                        <path d="M0 0 Q 100 50 0 100 L 0 100 L 0 0 Z" />
                    </svg>
                </div>

                {/* BACKGROUND IMAGE */}
                <img src="/image/login.jpg" alt="POS Background" className="absolute inset-0 w-full h-full object-cover scale-100 opacity-90 transition-transform duration-[15000ms] hover:scale-110" />
                
                {/* OVERLAY GRADASI SINEMATIK */}
                <div className="absolute inset-0 bg-gradient-to-tr from-indigo-950 via-indigo-950/60 to-transparent"></div>
                <div className="absolute inset-0 bg-indigo-900/10 mix-blend-overlay"></div>
                
                {/* KONTEN BRANDING */}
                <div className="relative z-10 w-full max-w-3xl px-24 text-left">
                    <div className="mb-10 inline-flex items-center gap-3 px-6 py-3 bg-white/5 backdrop-blur-2xl rounded-full border border-white/20 shadow-2xl animate-pulse-subtle">
                        <IconSparkles className="text-yellow-400" size={20} />
                        <span className="text-[11px] font-black uppercase tracking-[0.4em] text-white">Advanced POS System</span>
                    </div>

                    <h2 className="text-8xl font-black uppercase leading-[0.85] tracking-tighter mb-12 italic text-white drop-shadow-[0_15px_40px_rgba(0,0,0,0.6)]">
                        KENDALI <br /> <span className="text-indigo-400">CERDAS</span> <br /> USAHA ANDA.
                    </h2>
                    
                    <div className="relative mb-14 max-w-xl">
                        <div className="absolute -left-6 top-0 bottom-0 w-1.5 bg-indigo-500 rounded-full shadow-[0_0_15px_rgba(129,140,248,0.8)]"></div>
                        <p className="text-xl font-medium text-slate-100 leading-relaxed pl-6 py-1">
                            Platform kasir modern yang menggabungkan kecepatan transaksi dengan sistem inventori berbasis cloud yang akurat.
                        </p>
                    </div>
                    
                    <div className="flex flex-wrap gap-4">
                        {["Real-time Sync", "Cloud Storage", "AI Security"].map((tag, i) => (
                            <div key={i} className="px-8 py-4 rounded-full bg-black/40 backdrop-blur-md border border-white/10 text-[10px] font-black uppercase tracking-[0.2em] text-white hover:bg-indigo-600/50 transition-all cursor-default shadow-xl">
                                {tag}
                            </div>
                        ))}
                    </div>
                </div>

                {/* DEKORATIF DOTS */}
                <div className="absolute top-10 right-10 opacity-[0.15] grid grid-cols-5 gap-3">
                    {[...Array(25)].map((_, i) => (
                        <div key={i} className="w-1.5 h-1.5 bg-indigo-300 rounded-full"></div>
                    ))}
                </div>

                {/* ICON INFINITY DEKORATIF */}
                <div className="absolute bottom-10 right-10 opacity-20 text-indigo-400 animate-spin-slow">
                    <IconInfinity size={100} stroke={1} />
                </div>
            </div>
            
            <style>{`
                .animate-spin-slow { animation: spin 20s linear infinite; }
                .animate-pulse-subtle { animation: pulseSubtle 4s ease-in-out infinite; }
                @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
                @keyframes pulseSubtle { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.7; transform: scale(0.98); } }
                .h-15 { height: 3.75rem; }
            `}</style>
        </div>
    );
}