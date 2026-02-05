import React from "react";
import { Head, Link } from "@inertiajs/react";
import { IconAlertTriangle, IconLock, IconError404, IconArrowLeft } from "@tabler/icons-react";

export default function Error({ status }) {
    // Konfigurasi Pesan Berdasarkan Status Code
    const title = {
        503: "Layanan Tidak Tersedia",
        500: "Kesalahan Server",
        404: "Halaman Tidak Ditemukan",
        403: "Akses Ditolak",
    }[status];

    const description = {
        503: "Maaf, kami sedang melakukan pemeliharaan. Silakan periksa kembali nanti.",
        500: "Ups, terjadi kesalahan pada server kami.",
        404: "Maaf, halaman yang Anda cari tidak dapat ditemukan.",
        403: "Maaf, Anda tidak memiliki izin untuk mengakses halaman ini (Khusus Admin).",
    }[status];

    const icons = {
        503: <IconAlertTriangle size={80} className="text-warning-500" />,
        500: <IconAlertTriangle size={80} className="text-danger-500" />,
        404: <IconError404 size={80} className="text-primary-500" />,
        403: <IconLock size={80} className="text-danger-500" />, // Ikon Gembok untuk 403
    }[status];

    return (
        <>
            <Head title={title} />
            <div className="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-900 p-4">
                <div className="w-full max-w-lg bg-white dark:bg-slate-800 rounded-3xl shadow-xl p-8 text-center border border-slate-100 dark:border-slate-700">
                    
                    {/* Icon Section */}
                    <div className="flex justify-center mb-6 animate-bounce-slow">
                        <div className="p-4 bg-slate-50 dark:bg-slate-900 rounded-full shadow-inner">
                            {icons}
                        </div>
                    </div>

                    {/* Text Section */}
                    <h1 className="text-4xl font-bold text-slate-800 dark:text-white mb-2">
                        {status}
                    </h1>
                    <h2 className="text-xl font-semibold text-slate-700 dark:text-slate-200 mb-4">
                        {title}
                    </h2>
                    <p className="text-slate-500 dark:text-slate-400 mb-8 leading-relaxed">
                        {description}
                    </p>

                    {/* Button Section */}
                    <div className="flex justify-center gap-4">
                        <Link
                            href="/"
                            className="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600 font-medium transition-all"
                        >
                            <IconArrowLeft size={20} />
                            Kembali
                        </Link>
                        
                        {/* Jika 403/Login, tawarkan ke Dashboard yang aman */}
                        <Link
                            href="/dashboard"
                            className="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-primary-500 text-white hover:bg-primary-600 font-medium shadow-lg shadow-primary-500/30 transition-all"
                        >
                            Ke Dashboard
                        </Link>
                    </div>
                </div>
            </div>
        </>
    );
}