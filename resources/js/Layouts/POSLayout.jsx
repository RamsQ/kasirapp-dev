import React, { useState, useEffect } from "react";
import { usePage, Link } from "@inertiajs/react";
import { Toaster } from "react-hot-toast";
import { useTheme } from "@/Context/ThemeSwitcherContext";
import {
    IconHome,
    IconHistory,
    IconSun,
    IconMoon,
    IconLogout,
    IconMenu2,
    IconX,
    IconUser,
} from "@tabler/icons-react";

export default function POSLayout({ children }) {
    const { auth } = usePage().props;
    const { darkMode, themeSwitcher } = useTheme();
    const [currentTime, setCurrentTime] = useState(new Date());
    const [showMobileMenu, setShowMobileMenu] = useState(false);

    // Update time every minute
    useEffect(() => {
        const timer = setInterval(() => {
            setCurrentTime(new Date());
        }, 60000);
        return () => clearInterval(timer);
    }, []);

    const formatTime = (date) => {
        return date.toLocaleTimeString("id-ID", {
            hour: "2-digit",
            minute: "2-digit",
        });
    };

    const formatDate = (date) => {
        return date.toLocaleDateString("id-ID", {
            weekday: "long",
            day: "numeric",
            month: "short",
            year: "numeric",
        });
    };

    return (
        <div className="min-h-screen flex flex-col bg-slate-50 dark:bg-slate-950">
            {/* Top Navigation Bar */}
            <header className="sticky top-0 z-50 h-16 flex items-center justify-between px-4 lg:px-6 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 shadow-sm">
                {/* Left Section - Logo & Time */}
                <div className="flex items-center gap-4 lg:gap-6">
                    {/* Mobile Menu Toggle */}
                    <button
                        onClick={() => setShowMobileMenu(!showMobileMenu)}
                        className="lg:hidden p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    >
                        {showMobileMenu ? (
                            <IconX
                                size={22}
                                className="text-slate-600 dark:text-slate-400"
                            />
                        ) : (
                            <IconMenu2
                                size={22}
                                className="text-slate-600 dark:text-slate-400"
                            />
                        )}
                    </button>

                    {/* Logo */}
                    <Link
                        href={route("dashboard")}
                        className="flex items-center gap-2"
                    >
                        <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center">
                            <span className="text-white font-bold text-sm">
                                K
                            </span>
                        </div>
                        <span className="hidden sm:block text-lg font-bold text-slate-800 dark:text-white">
                            KASIR
                        </span>
                    </Link>

                    {/* Divider */}
                    <div className="hidden md:block w-px h-8 bg-slate-200 dark:bg-slate-700" />

                    {/* Time & Date */}
                    <div className="hidden md:flex items-center gap-3">
                        <div className="text-2xl font-semibold text-slate-800 dark:text-white tabular-nums">
                            {formatTime(currentTime)}
                        </div>
                        <div className="text-sm text-slate-500 dark:text-slate-400">
                            {formatDate(currentTime)}
                        </div>
                    </div>
                </div>

                {/* Right Section - Actions & User */}
                <div className="flex items-center gap-2 lg:gap-3">
                    {/* Quick Actions */}
                    <nav className="hidden lg:flex items-center gap-1">
                        <Link
                            href={route("dashboard")}
                            className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-white dark:hover:bg-slate-800 transition-colors"
                        >
                            <IconHome size={18} />
                            <span>Dashboard</span>
                        </Link>
                        <Link
                            href={route("transactions.history")}
                            className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-white dark:hover:bg-slate-800 transition-colors"
                        >
                            <IconHistory size={18} />
                            <span>Riwayat</span>
                        </Link>
                    </nav>

                    {/* Divider */}
                    <div className="hidden lg:block w-px h-8 bg-slate-200 dark:bg-slate-700" />

                    {/* Theme Toggle */}
                    <button
                        onClick={themeSwitcher}
                        className="p-2.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors min-w-touch min-h-touch flex items-center justify-center"
                        title={darkMode ? "Light Mode" : "Dark Mode"}
                    >
                        {darkMode ? (
                            <IconSun size={20} className="text-amber-500" />
                        ) : (
                            <IconMoon size={20} className="text-slate-500" />
                        )}
                    </button>

                    {/* User Info */}
                    <div className="flex items-center gap-3 pl-2 lg:pl-3 border-l border-slate-200 dark:border-slate-700">
                        <div className="hidden sm:block text-right">
                            <p className="text-sm font-medium text-slate-700 dark:text-slate-200">
                                {auth.user.name}
                            </p>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Kasir
                            </p>
                        </div>
                        <img
                            src={
                                auth.user.avatar ||
                                `https://ui-avatars.com/api/?name=${auth.user.name}&background=6366f1&color=fff`
                            }
                            alt={auth.user.name}
                            className="w-9 h-9 rounded-full ring-2 ring-slate-200 dark:ring-slate-700"
                        />
                    </div>

                    {/* Logout */}
                    <Link
                        href={route("logout")}
                        method="post"
                        as="button"
                        className="hidden lg:flex p-2.5 rounded-lg text-slate-500 hover:text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950/50 transition-colors min-w-touch min-h-touch items-center justify-center"
                        title="Logout"
                    >
                        <IconLogout size={20} />
                    </Link>
                </div>
            </header>

            {/* Mobile Menu Overlay */}
            {showMobileMenu && (
                <div
                    className="lg:hidden fixed inset-0 z-40 bg-black/50"
                    onClick={() => setShowMobileMenu(false)}
                >
                    <div
                        className="absolute top-16 left-0 right-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 shadow-lg animate-slide-up"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <nav className="p-4 space-y-2">
                            <Link
                                href={route("dashboard")}
                                className="flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors"
                            >
                                <IconHome size={20} />
                                <span className="font-medium">Dashboard</span>
                            </Link>
                            <Link
                                href={route("transactions.history")}
                                className="flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors"
                            >
                                <IconHistory size={20} />
                                <span className="font-medium">
                                    Riwayat Transaksi
                                </span>
                            </Link>
                            <Link
                                href={route("profile.edit")}
                                className="flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors"
                            >
                                <IconUser size={20} />
                                <span className="font-medium">Profil</span>
                            </Link>
                            <hr className="border-slate-200 dark:border-slate-700" />
                            <Link
                                href={route("logout")}
                                method="post"
                                as="button"
                                className="flex items-center gap-3 px-4 py-3 rounded-xl text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950/50 transition-colors w-full"
                            >
                                <IconLogout size={20} />
                                <span className="font-medium">Keluar</span>
                            </Link>
                        </nav>
                    </div>
                </div>
            )}

            {/* Main Content - Full Height */}
            <main className="flex-1 overflow-hidden">
                <Toaster
                    position="top-right"
                    toastOptions={{
                        className: "text-sm",
                        duration: 3000,
                        style: {
                            background: darkMode ? "#1e293b" : "#fff",
                            color: darkMode ? "#f1f5f9" : "#1e293b",
                            border: `1px solid ${
                                darkMode ? "#334155" : "#e2e8f0"
                            }`,
                        },
                    }}
                />
                {children}
            </main>
        </div>
    );
}
