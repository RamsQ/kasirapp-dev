import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout"; 
import { Head } from "@inertiajs/react";
import UpdateProfileInformationForm from "./Partials/UpdateProfileInformationForm";
import UpdatePasswordForm from "./Partials/UpdatePasswordForm";
import DeleteUserForm from "./Partials/DeleteUserForm";

// Jalur ini disesuaikan agar Vite tidak bingung saat Build
import FaceRegistration from "@/Components/FaceRegistration"; 

import { IconUserCircle, IconShieldLock, IconUserX, IconFaceId } from "@tabler/icons-react";

export default function Edit({ auth, mustVerifyEmail, status }) {
    return (
        <DashboardLayout user={auth.user}>
            <Head title="Pengaturan Profil" />

            <div className="max-w-7xl mx-auto space-y-6 pb-20">
                {/* Header Halaman */}
                <div className="flex flex-col gap-1 mb-2 px-4 md:px-0">
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
                        Pengaturan Profil
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Kelola informasi akun, foto profil, dan keamanan password Anda.
                    </p>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    
                    {/* KARTU 1: INFORMASI PROFIL & FOTO */}
                    <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-6 md:p-8 shadow-sm transition-colors duration-200">
                        <div className="flex items-center gap-4 mb-8">
                            <div className="w-12 h-12 rounded-2xl bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center text-primary-600">
                                <IconUserCircle size={28} />
                            </div>
                            <div>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white">Biodata</h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">Foto profil dan info dasar</p>
                            </div>
                        </div>
                        
                        <div className="text-slate-900 dark:text-slate-100">
                            <UpdateProfileInformationForm
                                mustVerifyEmail={mustVerifyEmail}
                                status={status}
                            />
                        </div>
                    </div>

                    {/* KARTU 2: GANTI PASSWORD */}
                    <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-6 md:p-8 shadow-sm transition-colors duration-200">
                        <div className="flex items-center gap-4 mb-8">
                            <div className="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center text-amber-600">
                                <IconShieldLock size={28} />
                            </div>
                            <div>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white">Keamanan</h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">Perbarui kata sandi akun</p>
                            </div>
                        </div>

                        <div className="text-slate-900 dark:text-slate-100">
                            <UpdatePasswordForm />
                        </div>
                    </div>

                    {/* [BARU] KARTU 3: VERIFIKASI WAJAH (FACE ID) */}
                    <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-6 md:p-8 shadow-sm transition-colors duration-200">
                        <div className="flex items-center gap-4 mb-8">
                            <div className="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center text-indigo-600">
                                <IconFaceId size={28} />
                            </div>
                            <div>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white">Verifikasi Wajah</h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">Gunakan wajah untuk login cepat</p>
                            </div>
                        </div>

                        <div className="text-slate-900 dark:text-slate-100">
                            {/* Memanggil komponen FaceRegistration */}
                            <FaceRegistration />
                        </div>
                    </div>

                    {/* KARTU 4: HAPUS AKUN */}
                    <div className="bg-rose-50/50 dark:bg-rose-950/10 rounded-[2.5rem] border border-rose-100 dark:border-rose-900/30 p-6 md:p-8 shadow-sm h-full">
                        <div className="flex items-center gap-4 mb-6 text-rose-600 dark:text-rose-500">
                            <div className="w-12 h-12 rounded-2xl bg-rose-100 dark:bg-rose-900/20 flex items-center justify-center">
                                <IconUserX size={28} />
                            </div>
                            <div>
                                <h3 className="text-lg font-bold">Hapus Akun</h3>
                                <p className="text-xs opacity-70 dark:text-rose-300">Tindakan ini permanen</p>
                            </div>
                        </div>

                        <div className="text-slate-900 dark:text-slate-100">
                            <DeleteUserForm className="max-w-xl" />
                        </div>
                    </div>

                </div>
            </div>
        </DashboardLayout>
    );
}