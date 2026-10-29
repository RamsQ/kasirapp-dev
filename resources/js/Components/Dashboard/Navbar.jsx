import React, { useEffect, useState } from "react";
import { usePage } from "@inertiajs/react";
import { IconMenu2, IconMoon, IconSun } from "@tabler/icons-react";
import AuthDropdown from "@/Components/Dashboard/AuthDropdown";
import Menu from "@/Utils/Menu";
import Notification from "@/Components/Dashboard/Notification";
import useMediaQuery from "@/Hooks/useMediaQuery";

export default function Navbar({
    toggleSidebar,
    toggleMobileNav,
    mobileNavOpen,
    themeSwitcher,
    darkMode,
}) {
    const { auth } = usePage().props;
    const menuNavigation = Menu();

    // Get current page title
    const links = menuNavigation.flatMap((item) => item.details);
    const sublinks = links
        .filter((item) => item.hasOwnProperty("subdetails"))
        .flatMap((item) => item.subdetails);

    const getCurrentTitle = () => {
        for (const link of links) {
            if (link.hasOwnProperty("subdetails")) {
                const activeSublink = sublinks.find((s) => s.active);
                if (activeSublink) return activeSublink.title;
            } else if (link.active) {
                return link.title;
            }
        }
        return "Dashboard";
    };

    // AuthDropdown memangkas nama dan email saat anggarannya mepet.
    //
    // Batasnya `< 768`, bukan `<= 768`. `md` di Tailwind berlaku mulai 768px
    // ke atas, jadi dengan `<=` tepat di 768px sidebar sudah tampil sebagai
    // kolom sementara AuthDropdown masih memakai versi drawer yang menutupi
    // layar. Threshold-nya juga ditulis ulang lewat matchQuery supaya tidak
    // lagi salinan angka yang bisa melenceng dari titik henti CSS.
    const isMobile = !useMediaQuery("(min-width: 768px)");

    const title = getCurrentTitle();

    return (
        <header
            className="sticky top-0 z-40 h-14 sm:h-16 flex items-center justify-between gap-2 px-3 sm:px-4 md:px-6
            bg-white dark:bg-slate-900
            border-b border-slate-200 dark:border-slate-800
            transition-colors duration-200"
        >
            {/* Left Section */}
            <div className="flex items-center gap-2 sm:gap-4 min-w-0">
                {/* Hamburger untuk HP: membuka drawer menu.
                    Sebelumnya tombol ini disembunyikan di bawah 768px DAN
                    sidebar-nya juga, jadi di HP aplikasi tidak punya navigasi
                    sama sekali. Sekarang keduanya hidup di layar sempit. */}
                <button
                    onClick={toggleMobileNav}
                    aria-label={mobileNavOpen ? "Tutup menu" : "Buka menu"}
                    aria-expanded={!!mobileNavOpen}
                    className="md:hidden h-10 w-10 shrink-0 flex items-center justify-center rounded-lg
                               text-slate-500 hover:text-slate-700 hover:bg-slate-100
                               dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800
                               transition-colors"
                >
                    <IconMenu2 size={22} strokeWidth={1.5} />
                </button>

                {/* Tombol ciut sidebar, hanya di layar lebar. */}
                <button
                    onClick={toggleSidebar}
                    aria-label={
                        "Ciutkan atau lebarkan sidebar"
                    }
                    className="hidden md:flex p-2 rounded-lg text-slate-500 hover:text-slate-700 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors"
                    title="Toggle Sidebar"
                >
                    <IconMenu2 size={20} strokeWidth={1.5} />
                </button>

                {/* Logo. Di HP judul halaman lebih berguna daripada logo
                    karena layar hanya 375px dan tidak ada sidebar yang
                    memberi konteks. */}
                <div className="md:hidden flex items-center gap-2 min-w-0 shrink-0">
                    <img src="/laku-pos-logo.jpg" alt="LakuPOS" className="w-7 h-7 rounded-lg object-contain shrink-0" />
                </div>

                {/* Current Page Title.
                    Di HP judul dibuat penuh karena tidak ada menu di samping;
                    di layar lebar judulnya dipotong karena harus berbagi
                    ruang dengan logo dan tombol. */}
                <div className="flex items-center min-w-0">
                    <div className="hidden md:block w-px h-6 bg-slate-200 dark:bg-slate-700 mr-4" />
                    <h1
                        className="font-semibold text-slate-800 dark:text-slate-200 truncate
                                   text-base md:text-base"
                        title={title}
                    >
                        {title}
                    </h1>
                </div>
            </div>

            {/* Right Section */}
            <div className="flex items-center gap-1 sm:gap-2 shrink-0">
                {/* Theme Toggle */}
                <button
                    onClick={themeSwitcher}
                    aria-label={darkMode ? "Mode terang" : "Mode gelap"}
                    className="h-10 w-10 sm:h-auto sm:w-auto sm:p-2.5 flex items-center justify-center rounded-xl text-slate-500 hover:text-slate-700 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors"
                    title={darkMode ? "Light Mode" : "Dark Mode"}
                >
                    {darkMode ? (
                        <IconSun
                            size={20}
                            strokeWidth={1.5}
                            className="text-amber-500"
                        />
                    ) : (
                        <IconMoon size={20} strokeWidth={1.5} />
                    )}
                </button>

                {/* Notifications */}
                <Notification />

                {/* Divider. Di HP dihilangkan: ruang 1px ini akan dipakai
                    untuk target lebih besar di tombol. */}
                <div className="hidden sm:block w-px h-8 bg-slate-200 dark:bg-slate-700 mx-1" />

                {/* User Dropdown */}
                <AuthDropdown auth={auth} isMobile={isMobile} />
            </div>
        </header>
    );
}
