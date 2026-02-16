import React from 'react';
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router } from '@inertiajs/react';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import FaceRegistration from "@/Components/FaceRegistration";
import { 
    IconUser, IconLock, IconFaceId, IconShieldCheck, 
    IconAlertCircle, IconAlertTriangle, IconTrash 
} from '@tabler/icons-react';
import Swal from 'sweetalert2';

export default function Index({ auth, mustVerifyEmail, status }) {
    // Mengecek apakah user memiliki role pimpinan
    const isOwner = auth.user.roles.some(role => role.name === 'super-admin' || role.name === 'owner');
    
    // Status Mandatory dari database
    const isFaceMandatory = auth.user.is_face_mandatory;

    // Fungsi Handle Reset System
    const handleResetSystem = () => {
        Swal.fire({
            title: 'RESET TOTAL SISTEM?',
            text: "Seluruh data transaksi, stok, bahan baku, dan antrean akan dihapus permanen. Tindakan ini tidak dapat dibatalkan!",
            icon: 'warning',
            input: 'password',
            inputAttributes: {
                autocapitalize: 'off',
                placeholder: 'Masukkan Password Admin Anda'
            },
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'YA, RESET SEMUANYA!',
            cancelButtonText: 'Batal',
            showLoaderOnConfirm: true,
            preConfirm: (password) => {
                if (!password) {
                    Swal.showValidationMessage('Password wajib diisi untuk verifikasi!');
                }
                return password;
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
            if (result.isConfirmed) {
                // MENGGUNAKAN POST untuk mengirim payload password ke backend
                router.post(route('system.reset'), { 
                    password: result.value 
                }, {
                    onSuccess: () => {
                        Swal.fire({
                            title: 'Sistem Dibersihkan!',
                            text: 'Semua data telah berhasil dihapus.',
                            icon: 'success',
                            confirmButtonColor: '#3b82f6'
                        }).then(() => {
                            // PAKSA RELOAD HALAMAN (Penting untuk membersihkan cache state Inertia)
                            window.location.reload();
                        });
                    },
                    onError: (errors) => {
                        // Menampilkan error spesifik dari Laravel (misal: password salah)
                        Swal.fire({
                            title: 'Gagal Reset!',
                            text: errors.password || errors.error || 'Terjadi kesalahan pada sistem.',
                            icon: 'error',
                            confirmButtonColor: '#ef4444'
                        });
                    }
                });
            }
        });
    };

    return (
        <>
            <Head title="Profil Saya" />

            <div className="space-y-6 pb-10">
                {/* Header Page */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white uppercase tracking-tighter">
                            Pengaturan Profil
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 font-medium">
                            Kelola informasi akun dan keamanan biometrik Anda
                        </p>
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* Sisi Kiri: Update Info & Foto */}
                    <div className="lg:col-span-7 space-y-6">
                        <div className="bg-white dark:bg-slate-900 shadow-sm border border-slate-200 dark:border-slate-800 rounded-3xl overflow-hidden transition-all">
                            <div className="p-6 sm:p-8">
                                <div className="flex items-center gap-4 mb-8">
                                    <div className="p-2.5 bg-primary-50 dark:bg-primary-900/20 rounded-2xl text-primary-600">
                                        <IconUser size={24} />
                                    </div>
                                    <h3 className="text-lg font-black text-slate-800 dark:text-white uppercase tracking-tight">
                                        Informasi Akun
                                    </h3>
                                </div>
                                <UpdateProfileInformationForm mustVerifyEmail={mustVerifyEmail} status={status} />
                            </div>
                        </div>

                        {/* DANGER ZONE: Hanya muncul untuk Super Admin / Owner */}
                        {isOwner && (
                            <div className="bg-red-50/50 dark:bg-red-950/10 border-2 border-red-100 dark:border-red-900/30 rounded-[2.5rem] p-6 sm:p-8 overflow-hidden">
                                <div className="flex items-center gap-4 mb-6">
                                    <div className="p-2.5 bg-red-500 text-white rounded-2xl shadow-lg shadow-red-500/20">
                                        <IconAlertTriangle size={24} />
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-black text-red-600 dark:text-red-400 uppercase tracking-tight italic">
                                            Danger Zone
                                        </h3>
                                        <p className="text-[10px] text-slate-500 font-black uppercase tracking-widest">Akses Khusus Pemilik</p>
                                    </div>
                                </div>

                                <div className="bg-white dark:bg-slate-900 p-6 rounded-3xl border border-red-100 dark:border-red-900/50 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-sm">
                                    <div className="max-w-md">
                                        <h4 className="text-sm font-black text-slate-800 dark:text-white uppercase mb-1">Reset Database Kasir</h4>
                                        <p className="text-xs text-slate-500 font-medium leading-relaxed">
                                            Hapus seluruh riwayat transaksi, laporan laba rugi, stok produk, bahan baku, dan riwayat shift. Gunakan fitur ini jika ingin memulai buku kas baru dari nol.
                                        </p>
                                    </div>
                                    <button 
                                        onClick={handleResetSystem}
                                        className="flex items-center justify-center gap-2 px-8 py-4 bg-red-600 hover:bg-red-700 text-white rounded-2xl text-xs font-black uppercase tracking-widest transition-all active:scale-95 shadow-xl shadow-red-600/20 shrink-0"
                                    >
                                        <IconTrash size={18} /> Reset Total
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Sisi Kanan: Keamanan & Face ID */}
                    <div className="lg:col-span-5 space-y-6">
                        
                        {/* [KEAMANAN BIOMETRIK] */}
                        <div className={`bg-white dark:bg-slate-900 shadow-xl border rounded-3xl overflow-hidden transition-all ${isFaceMandatory ? 'border-indigo-500 ring-4 ring-indigo-500/10' : 'border-slate-200 dark:border-slate-800'}`}>
                            <div className="p-6 sm:p-8">
                                <div className="flex items-center justify-between mb-6">
                                    <div className="flex items-center gap-4">
                                        <div className="p-2.5 bg-indigo-50 dark:bg-indigo-900/30 rounded-2xl text-indigo-600">
                                            <IconFaceId size={24} />
                                        </div>
                                        <div>
                                            <h3 className="text-lg font-black text-slate-800 dark:text-white uppercase tracking-tight">
                                                Face ID Setup
                                            </h3>
                                            <p className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Otentikasi Biometrik</p>
                                        </div>
                                    </div>
                                    {isFaceMandatory && (
                                        <div className="flex items-center gap-1 bg-indigo-600 text-white px-3 py-1 rounded-full shadow-lg shadow-indigo-500/30">
                                            <IconShieldCheck size={14} />
                                            <span className="text-[10px] font-black uppercase">Wajib</span>
                                        </div>
                                    )}
                                </div>

                                {isFaceMandatory && !auth.user.face_data && (
                                    <div className="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-2xl flex items-start gap-3 animate-pulse">
                                        <IconAlertCircle className="text-amber-600 shrink-0" size={20} />
                                        <p className="text-[11px] font-bold text-amber-700 dark:text-amber-400 uppercase leading-tight">
                                            Perhatian: Akun Anda diwajibkan menggunakan wajah. Segera daftarkan wajah Anda agar tidak terkunci saat login berikutnya.
                                        </p>
                                    </div>
                                )}

                                <p className="text-xs text-slate-500 mb-6 font-medium leading-relaxed">
                                    Daftarkan sampel wajah Anda untuk mengaktifkan fitur login tanpa password yang lebih aman dan cepat.
                                </p>
                                
                                <FaceRegistration user={auth.user} />
                            </div>
                        </div>

                        {/* Kartu Ganti Password */}
                        <div className="bg-white dark:bg-slate-900 shadow-sm border border-slate-200 dark:border-slate-800 rounded-3xl overflow-hidden transition-all">
                            <div className="p-6 sm:p-8">
                                <div className="flex items-center gap-4 mb-8">
                                    <div className="p-2.5 bg-rose-50 dark:bg-rose-900/20 rounded-2xl text-rose-600">
                                        <IconLock size={24} />
                                    </div>
                                    <h3 className="text-lg font-black text-slate-800 dark:text-white uppercase tracking-tight">
                                        Ubah Password
                                    </h3>
                                </div>
                                <UpdatePasswordForm />
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;