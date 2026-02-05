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
    IconCamera,
    IconX,
    IconAlertTriangle 
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

    // --- PERBAIKAN LOGIKA: Cek Status Mandatory & Ketersediaan Data Wajah ---
    const checkEmailStatus = async (email) => {
        if (!email.includes('@')) return;
        try {
            const res = await axios.post('/face-auth/fetch-user', { email });
            if (res.data.status === 'success') {
                /**
                 * Kunci form HANYA jika:
                 * 1. is_mandatory bernilai true
                 * 2. DAN face_data tidak kosong (sudah pernah daftar wajah)
                 */
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
                return Swal.fire('Belum Terdaftar', 'Wajah Anda belum didaftarkan. Silakan login menggunakan password terlebih dahulu untuk mendaftarkan wajah.', 'info');
            }

            // Aktifkan Kamera
            setIsScanning(true);
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { 
                    facingMode: "user",
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                } 
            });
            if (videoRef.current) {
                videoRef.current.srcObject = stream;
            }

            // Siapkan Matcher
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
                        stopCamera();
                        
                        const loginRes = await axios.post('/face-auth/login', { user_id: res.data.user_id });
                        if (loginRes.data.status === 'success') {
                            router.visit(loginRes.data.redirect);
                        }
                    }
                }
            }, 1000);

        } catch (error) {
            console.error(error);
            setFaceAuthLoading(false);
            setIsScanning(false);
            Swal.fire('Error', 'Terjadi kesalahan sistem atau kamera tidak diizinkan.', 'error');
        }
    };

    const stopCamera = () => {
        if (videoRef.current && videoRef.current.srcObject) {
            videoRef.current.srcObject.getTracks().forEach(track => track.stop());
            videoRef.current.srcObject = null;
        }
        setIsScanning(false);
        setFaceAuthLoading(false);
    };

    // --- LOGIKA SUBMIT DENGAN DOUBLE PROTECTION (SMART) ---
    const submit = async (e) => {
        e.preventDefault();

        // Cek Ulang ke Server untuk memastikan data wajah benar-benar sudah ada
        try {
            const check = await axios.post('/face-auth/fetch-user', { email: data.email });
            if (check.data.status === 'success') {
                const isReadyForLock = check.data.is_mandatory && check.data.face_data !== null;
                
                if (isReadyForLock) {
                    setIsFaceMandatory(true);
                    return Swal.fire({
                        title: 'Keamanan Aktif',
                        text: 'Akun ini wajib menggunakan Face ID karena data wajah sudah terdaftar.',
                        icon: 'error',
                        confirmButtonColor: '#4f46e5'
                    });
                }
            }
        } catch (err) {
            console.error("Gagal verifikasi keamanan");
        }

        // Jika user baru (face_data null), post(route("login")) akan tetap dijalankan
        post(route("login"), {
            onFinish: () => reset("password"),
        });
    };

    return (
        <div className="min-h-screen flex bg-slate-50 dark:bg-slate-950 transition-colors duration-300 font-sans">
            <Head title="Masuk Ke Sistem" />
            
            <div className="flex-1 flex items-center justify-center p-6 sm:p-12 bg-white dark:bg-slate-900 shadow-xl z-10 transition-colors duration-300">
                <div className="w-full max-w-md">
                    <div className="mb-10 text-center lg:text-left">
                        <div className="flex items-center justify-center lg:justify-start gap-3 mb-8 group">
                            <Link href="/" className="flex items-center gap-3">
                                <div className="w-12 h-12 rounded-2xl bg-primary-600 flex items-center justify-center shadow-lg shadow-primary-500/20 group-hover:scale-105 transition-transform">
                                    <IconShoppingCart size={28} className="text-white" />
                                </div>
                                <span className="text-2xl font-black tracking-tighter text-slate-900 dark:text-white uppercase">
                                    POS SYSTEM
                                </span>
                            </Link>
                        </div>
                        <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight leading-none uppercase">
                            Selamat Datang
                        </h1>
                        <p className="mt-3 text-slate-500 dark:text-slate-400 font-medium">
                            Silakan masuk untuk mengelola transaksi toko Anda hari ini.
                        </p>
                    </div>

                    {status && (
                        <div className="mb-6 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm border border-emerald-100 dark:border-emerald-800 font-bold">
                            {status}
                        </div>
                    )}

                    {isScanning ? (
                        <div className="mb-8 space-y-4">
                            <div className="relative mx-auto w-full aspect-square max-w-[300px] bg-black rounded-3xl overflow-hidden border-4 border-primary-500 shadow-2xl">
                                <video ref={videoRef} autoPlay muted className="w-full h-full object-cover scale-x-[-1]" />
                                <div className="absolute inset-0 border-[3px] border-dashed border-white/30 rounded-full m-8 animate-spin-slow pointer-events-none"></div>
                                <div className="absolute inset-x-0 bottom-4 flex justify-center">
                                    <span className="px-4 py-1 bg-primary-600 text-white text-[10px] font-black uppercase rounded-full animate-pulse">Memindai Wajah...</span>
                                </div>
                            </div>
                            <button onClick={stopCamera} className="w-full py-3 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-2xl font-bold uppercase text-xs flex items-center justify-center gap-2 hover:bg-red-50 hover:text-red-500 transition-all">
                                <IconX size={18} /> Batalkan Pemindaian
                            </button>
                        </div>
                    ) : (
                        <form onSubmit={submit} className="space-y-5">
                            <div>
                                <label className="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Email Kasir</label>
                                <div className="relative group">
                                    <div className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-primary-500 transition-colors">
                                        <IconMail size={20} />
                                    </div>
                                    <input 
                                        type="email" 
                                        value={data.email} 
                                        autoComplete="username"
                                        onChange={e => {
                                            setData('email', e.target.value);
                                            checkEmailStatus(e.target.value);
                                        }}
                                        placeholder="admin@pos.com"
                                        className={`w-full h-13 pl-12 pr-4 rounded-2xl border-2 ${
                                            errors.email ? 'border-red-500 ring-red-500/10' : 'border-slate-100 dark:border-slate-800'
                                        } bg-slate-50 dark:bg-slate-800 dark:text-white outline-none focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10 transition-all`}
                                    />
                                </div>
                                {errors.email && <p className="text-red-500 text-[10px] mt-1.5 font-black uppercase tracking-tight">{errors.email}</p>}
                            </div>

                            <button 
                                type="button"
                                onClick={handleFaceLogin}
                                disabled={!modelsLoaded || faceAuthLoading}
                                className={`w-full h-14 border-2 ${
                                    isFaceMandatory 
                                    ? 'bg-indigo-600 border-indigo-600 text-white shadow-lg shadow-indigo-500/30' 
                                    : 'border-primary-600/20 text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/10'
                                } rounded-2xl font-black uppercase text-[10px] tracking-widest transition-all flex items-center justify-center gap-3 disabled:opacity-50`}
                            >
                                {faceAuthLoading ? <IconLoader2 className="animate-spin" size={18} /> : <IconFaceId size={20} />}
                                {isFaceMandatory ? "WAJIB VERIFIKASI WAJAH" : (modelsLoaded ? "Login Wajah" : "Memuat AI Wajah...")}
                            </button>

                            {isFaceMandatory && (
                                <div className="p-4 bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-800 rounded-2xl flex items-start gap-3 animate-in slide-in-from-top duration-300 shadow-sm">
                                    <IconAlertTriangle className="text-amber-600 shrink-0" size={20} />
                                    <p className="text-[11px] font-bold text-amber-700 dark:text-amber-400 uppercase leading-tight">
                                        Keamanan Wajah Aktif: Anda sudah mendaftarkan wajah. Login password dilarang untuk keamanan kasir.
                                    </p>
                                </div>
                            )}

                            {!isFaceMandatory && (
                                <>
                                    <div className="relative py-2">
                                        <div className="absolute inset-0 flex items-center"><div className="w-full border-t border-slate-100 dark:border-slate-800"></div></div>
                                        <div className="relative flex justify-center text-[10px] uppercase font-black text-slate-300 dark:text-slate-700 bg-white dark:bg-slate-900 px-4">Opsi Login Password</div>
                                    </div>

                                    <div>
                                        <label className="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Kata Sandi</label>
                                        <div className="relative group">
                                            <div className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-primary-500 transition-colors">
                                                <IconLock size={20} />
                                            </div>
                                            <input 
                                                type={showPassword ? "text" : "password"}
                                                value={data.password} 
                                                autoComplete="current-password"
                                                onChange={e => setData('password', e.target.value)}
                                                placeholder="••••••••"
                                                className={`w-full h-13 pl-12 pr-12 rounded-2xl border-2 ${
                                                    errors.password ? 'border-red-500 ring-red-500/10' : 'border-slate-100 dark:border-slate-800'
                                                } bg-slate-50 dark:bg-slate-800 dark:text-white outline-none focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10 transition-all`}
                                            />
                                            <button 
                                                type="button" 
                                                onClick={() => setShowPassword(!showPassword)} 
                                                className="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-primary-500 transition-colors"
                                            >
                                                {showPassword ? <IconEyeOff size={20} /> : <IconEye size={20} />}
                                            </button>
                                        </div>
                                    </div>

                                    <div className="flex items-center justify-between">
                                        <label className="flex items-center gap-2 cursor-pointer group">
                                            <input type="checkbox" checked={data.remember} onChange={e => setData('remember', e.target.checked)} className="w-5 h-5 rounded-lg border-slate-200 dark:border-slate-800 text-primary-600 focus:ring-primary-500 transition-all cursor-pointer shadow-sm" />
                                            <span className="text-sm font-bold text-slate-500 dark:text-slate-400 group-hover:text-primary-500 transition-colors">Ingat Saya</span>
                                        </label>
                                        {canResetPassword && (
                                            <Link href={route("password.request")} className="text-sm font-bold text-primary-600 hover:text-primary-700 underline underline-offset-4 transition-all">Lupa Password?</Link>
                                        )}
                                    </div>

                                    <button 
                                        type="submit" 
                                        disabled={processing}
                                        className="w-full h-14 bg-primary-600 text-white rounded-2xl font-black uppercase tracking-widest hover:bg-primary-700 disabled:opacity-50 transition-all shadow-lg shadow-primary-500/20 flex items-center justify-center gap-3 active:scale-[0.98]"
                                    >
                                        {processing ? <IconLoader2 className="animate-spin" size={24} /> : "Masuk Sekarang"}
                                    </button>
                                </>
                            )}
                        </form>
                    )}
                    
                    <div className="mt-8 text-center">
                        <p className="text-sm text-slate-500 dark:text-slate-400 font-bold uppercase tracking-tight">
                            Belum memiliki akun? {" "}
                            <Link href={route("register")} className="text-primary-600 hover:text-primary-700 font-black decoration-primary-600/30 hover:decoration-primary-600 underline underline-offset-4 transition-all">Daftar Sekarang</Link>
                        </p>
                    </div>
                </div>
            </div>

            <div className="hidden lg:flex flex-1 relative bg-slate-100 items-center justify-center overflow-hidden">
                <img 
                    src="/image/kasir.jpg" 
                    alt="POS Background" 
                    className="absolute inset-0 w-full h-full object-cover scale-105 opacity-90"
                    onError={(e) => { e.target.onerror = null; e.target.src = 'https://images.unsplash.com/photo-1556742049-13efd9395b46?q=80&w=1920'; }}
                />
                <div className="absolute inset-0 bg-gradient-to-br from-slate-900/60 via-primary-900/20 to-slate-900/60 mix-blend-multiply"></div>
                <div className="absolute inset-0 bg-white/5 backdrop-blur-[0.5px]"></div>
                
                <div className="relative z-10 text-white p-16 max-w-2xl">
                    <h2 className="text-6xl font-black uppercase leading-[1.1] tracking-tighter mb-8 italic drop-shadow-lg">
                        Solusi Cerdas <br /> Untuk Kasir Anda.
                    </h2>
                    <p className="text-lg font-medium text-white leading-relaxed mb-10 max-w-lg border-l-4 border-primary-500 pl-6 backdrop-blur-md bg-black/20 py-2 rounded-r-xl shadow-lg">
                        Didesain untuk kecepatan transaksi dan akurasi stok. Kelola inventori dan laporan otomatis dalam satu platform terintegrasi.
                    </p>
                    
                    <div className="flex flex-wrap gap-3">
                        {["Stok Real-time", "Manajemen Diskon", "Laporan", "Inventori", "Face ID Login"].map((tag, i) => (
                            <div key={i} className="px-5 py-2.5 rounded-xl bg-primary-600/30 backdrop-blur-md border border-white/20 text-[10px] font-black uppercase tracking-widest text-white shadow-2xl">
                                {tag}
                            </div>
                        ))}
                    </div>
                </div>
                <div className="absolute inset-0 opacity-[0.08]" style={{ backgroundImage: `radial-gradient(#fff 1px, transparent 0)`, backgroundSize: `40px 40px` }}></div>
            </div>
            
            <style>{`
                .animate-spin-slow {
                    animation: spin 8s linear infinite;
                }
                @keyframes spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
            `}</style>
        </div>
    );
}