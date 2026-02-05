import React from 'react';
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head } from '@inertiajs/react';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import FaceRegistration from "@/Components/FaceRegistration"; // Pastikan file ini ada di folder Components
import { IconUser, IconLock, IconFaceId, IconShieldCheck, IconAlertCircle } from '@tabler/icons-react';

export default function Index({ auth, mustVerifyEmail, status }) {
    // Mengecek apakah user memiliki role pimpinan
    const isOwner = auth.user.roles.some(role => role.name === 'super-admin' || role.name === 'owner');
    
    // Status Mandatory dari database
    const isFaceMandatory = auth.user.is_face_mandatory;

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
                    <div className="lg:col-span-7">
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
                    </div>

                    {/* Sisi Kanan: Keamanan & Face ID */}
                    <div className="lg:col-span-5 space-y-6">
                        
                        {/* [KEAMANAN BIOMETRIK] - WAJIB UNTUK SEMUA USER AGAR BISA DAFTAR WAJAH */}
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
                                
                                {/* Komponen Utama Registrasi Wajah */}
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