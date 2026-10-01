import React, { useCallback, useEffect, useState } from "react";
import Sidebar from "@/Components/Dashboard/Sidebar";
import Navbar from "@/Components/Dashboard/Navbar";
import { Toaster } from "react-hot-toast";
import { useTheme } from "@/Context/ThemeSwitcherContext";
import useMediaQuery from "@/Hooks/useMediaQuery";

export default function AppLayout({ children }) {
    const { darkMode, themeSwitcher } = useTheme();

    // Sakelar sidebar untuk layar lebar. Persisten karena ini pilihan
    // perangkat, bukan pilihan tampilan.
    const [sidebarOpen, setSidebarOpen] = useState(
        localStorage.getItem("sidebarOpen") === "true"
    );

    // Keinginan pengguna: drawer dibuka atau tidak.
    //
    // SENGJAJA tidak dipersist: menu di HP sebaiknya selalu tertutup saat
    // halaman dibuka. Kalau drawer tersimpan terbuka, kasir membuka aplikasi
    // dan langsung tidak melihat apa pun kecuali panelnya.
    const [mobileNavOpen, setMobileNavOpen] = useState(false);

    // Drawer hanya ada di bawah titik henti `md` Tailwind.
    //
    // Angka 768 tidak ditulis ulang di sini. Dulu setiap tempat mengulang sendiri
    // lewat listener `resize` + `window.innerWidth`, dan listener itu punya
    // dua masalah: ia hanya aktif selama drawer terbuka, dan ia bisa terlewat
    // begitu saja. Akibatnya kasir yang membuka drawer di HP lalu memutar ke
    // landscape mendapati `body { overflow: hidden }` masih terkunci padahal
    // drawer-nya sudah tidak terlihat — halaman tidak bisa digeser.
    //
    // Sekarang status sebenarnya dihitung ulang setiap render dari aturan
    // media, jadi walau state-nya tertinggal, yang tampil tetap benar.
    const isWideScreen = useMediaQuery("(min-width: 768px)");
    const drawerVisible = mobileNavOpen && !isWideScreen;

    useEffect(() => {
        localStorage.setItem("sidebarOpen", sidebarOpen);
    }, [sidebarOpen]);

    // Memperlebar jendela menutup drawer dan melepas kuncinya. Efek ini
    // diperlukan, bukan cuma tampilan: `body.style.overflow` di bawah
    // bergantung pada nilai yang sama.
    useEffect(() => {
        if (isWideScreen && mobileNavOpen) {
            setMobileNavOpen(false);
        }
    }, [isWideScreen, mobileNavOpen]);

    const toggleSidebar = useCallback(() => setSidebarOpen((v) => !v), []);
    const closeMobileNav = useCallback(() => setMobileNavOpen(false), []);
    const toggleMobileNav = useCallback(() => setMobileNavOpen((v) => !v), []);

    // Escape menutup drawer. Listener dipasang tanpa syarat state supaya
    // tetap ada saat drawer baru saja dibuka.
    useEffect(() => {
        const handleKeydown = (event) => {
            if (event.key === "Escape") setMobileNavOpen(false);
        };

        window.addEventListener("keydown", handleKeydown);
        return () => window.removeEventListener("keydown", handleKeydown);
    }, []);

    // Halaman di belakang drawer harus berhenti ikut terserialojar saat
    // gestur menarik di layar sentuh. Tanpa ini, sidebar ikut tergeser
    // bersama jari lalu halaman terasa "melar" begitu drawer ditutup.
    //
    // Yang dikunci adalah nilai yang benar-benar tampil (`drawerVisible`),
    // bukan keinginan pengguna — kalau intent-nya tertinggal, halaman
    // tidak akan terkunci selamanya.
    useEffect(() => {
        if (!drawerVisible) return;

        const previous = document.body.style.overflow;
        document.body.style.overflow = "hidden";

        return () => {
            document.body.style.overflow = previous;
        };
    }, [drawerVisible]);

    return (
        <div className="min-h-screen flex bg-slate-100 dark:bg-slate-950 transition-colors duration-200">
            {/* Sidebar menerima props sidebarOpen */}
            <Sidebar
                sidebarOpen={sidebarOpen}
                mobileOpen={drawerVisible}
                onCloseMobile={closeMobileNav}
            />

            <div className="flex-1 flex flex-col min-w-0">
                <Navbar
                    toggleSidebar={toggleSidebar}
                    toggleMobileNav={toggleMobileNav}
                    mobileNavOpen={drawerVisible}
                    themeSwitcher={themeSwitcher}
                    darkMode={darkMode}
                />
                {/* `min-w-0` itu wajib, bukan hiasan. Flex child punya
                    `min-width: auto` secara bawaan, jadi tanpa itu kolom ini
                    menyusut mengikuti layar dan ikut melebar jadi
                    scroll horizontal untuk SELURUH halaman — termasuk header
                    dan sidebar yang seharusnya tetap diam di tempat. */}
                <main className="flex-1 min-w-0">
                    <div className="w-full py-4 sm:py-6 px-3 sm:px-4 md:px-6 lg:px-8 pb-20 md:pb-6">
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
                                    borderRadius: "12px",
                                },
                            }}
                        />
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
