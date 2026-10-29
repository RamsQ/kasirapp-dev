import React, { useCallback, useEffect, useState } from "react";
import { usePage } from "@inertiajs/react";
import LinkItem from "@/Components/Dashboard/LinkItem";
import LinkItemDropdown from "@/Components/Dashboard/LinkItemDropdown";
import MenuSection from "@/Components/Dashboard/MenuSection";
import Menu from "@/Utils/Menu";

/**
 * Isi sidebar: logo, kartu user, daftar menu, dan versi.
 *
 * Dipisah dari wrapper-nya karena sidebar dirender DUA KALI di layout
 * yang sama: sekali di samping konten untuk layar lebar, sekali di dalam
 * drawer geser untuk layar sempit. Menulis ulang daftar menu dua kali
 * berarti setiap menu baru harus ingat ditambahkan di kedua tempat, dan
 * cepat atau lambat salah satu akan tertinggal.
 *
 * `mode` menentukan lebar tampilannya:
 *  - "desktop" mengikuti sakelar buka/tutup milik pengguna (260px / 80px)
 *  - "drawer"  selalu lebar penuh, karena layar sudah sempit dan menu
 *              sempit 80px di layar 375px hanya menyisakan 295px isi
 */
function SidebarBody({ sidebarOpen, mode, onNavigate }) {
    // `url` ada di level atas usePage(), bukan di dalam props. Menu.jsx juga
    // membacanya dari sana.
    const { url } = usePage();
    const { auth } = usePage().props;
    const menuNavigation = Menu();

    // Drawer selalu menampilkan label, apa pun state sakelar desktop.
    const wide = mode === "drawer" ? true : sidebarOpen;

    /**
     * Apakah grup ini memuat halaman yang sedang dibuka?
     *
     * `href` dari Ziggy `route()` berisi origin juga
     * (`http://127.0.0.1:8000/dashboard/recipes`), sedangkan `url` Inertia
     * cuma path (`/dashboard/recipes`). Kalau dibandingkan mentah, tidak akan
     * pernah sama dan grup tidak pernah terbuka sendiri.
     */
    function sectionContainsActiveItem(section) {
        return section.details.some((detail) => {
            if (detail.subdetails) {
                return detail.subdetails.some((child) => isActive(child.href));
            }
            return isActive(detail.href);
        });
    }

    /**
     * Ubah href apa pun jadi path tanpa query, slash akhir, dan origin.
     */
    function toPath(value) {
        const raw = String(value || "").trim();

        if (raw === "") return "";

        let path = raw;

        if (/^https?:\/\//i.test(raw)) {
            try {
                path = new URL(raw).pathname;
            } catch {
                // URL rusak: biarkan nilainya dipakai apa adanya.
            }
        }

        return path.split("?")[0].replace(/\/+$/, "");
    }

    function isActive(href) {
        const path = toPath(href);
        const current = toPath(url);

        if (path === "") return false;

        return current === path || current.startsWith(path + "/");
    }

    // Grup yang bisa dibuka-tutup.
    //
    // Default-nya TERTUTUP supaya sidebar tidak memanjang ke bawah. Grup
    // yang sedang berisi halaman aktif tetap otomatis terbuka: kalau kasir
    // sudah di halaman Resep dan grup Inventory Intelligence tertutup, dia
    // akan mengira menunya hilang dan mencari-cari di tempat lain.
    const [openedSections, setOpenedSections] = useState(() => {
        const open = new Set();

        menuNavigation.forEach((section, index) => {
            if (section.collapsible && sectionContainsActiveItem(section)) {
                open.add(index);
            }
        });

        return open;
    });

    // Navigasi Inertia tidak memuat ulang Sidebar, jadi state buka-tutupnya
    // tetap hidup. Kalau halaman aktif pindah ke grup yang tadinya tertutup,
    // grup itu harus dibuka lagi — kalau tidak, kasir mendarat di halaman
    // Resep tapi tidak melihat Resep ada di menunya.
    useEffect(() => {
        setOpenedSections((previous) => {
            const next = new Set(previous);
            let changed = false;

            menuNavigation.forEach((section, index) => {
                if (section.collapsible && sectionContainsActiveItem(section) && !next.has(index)) {
                    next.add(index);
                    changed = true;
                }
            });

            return changed ? next : previous;
        });
    }, [url]);

    const toggleSection = useCallback((index) => {
        setOpenedSections((previous) => {
            const next = new Set(previous);
            if (next.has(index)) next.delete(index);
            else next.add(index);
            return next;
        });
    }, []);

    // Helper untuk mendapatkan avatar/foto profil
    const userAvatar = auth.user.image
        ? (auth.user.image.startsWith("http")
            ? auth.user.image
            : `/storage/users/${auth.user.image}`)
        : `https://ui-avatars.com/api/?name=${encodeURIComponent(auth.user.name)}&background=6366f1&color=fff`;

    return (
        <React.Fragment>
            {/* Logo */}
            <div className="flex items-center justify-center h-16 shrink-0 border-b border-slate-100 dark:border-slate-800">
                {wide ? (
                    <div className="flex items-center gap-2">
                        <img src="/laku-pos-logo.jpg" alt="LakuPOS" className="w-8 h-8 rounded-lg object-contain" />
                        <span className="text-xl font-bold text-slate-800 dark:text-white uppercase tracking-tighter font-sans">
                            LakuPOS
                        </span>
                    </div>
                ) : (
                    <img src="/laku-pos-logo.jpg" alt="LakuPOS" className="w-8 h-8 rounded-lg object-contain" />
                )}
            </div>

            {/* User Info */}
            <div
                className={`
                p-3 shrink-0 border-b border-slate-100 dark:border-slate-800
                ${wide ? "flex items-center gap-3" : "flex justify-center"}
            `}
            >
                <img
                    src={userAvatar}
                    className={`rounded-full ring-2 ring-slate-100 dark:ring-slate-800 object-cover ${
                        wide ? "w-10 h-10" : "w-8 h-8"
                    }`}
                    alt={auth.user.name}
                    onError={(e) => {
                        e.target.src = `https://ui-avatars.com/api/?name=${encodeURIComponent(auth.user.name)}&background=6366f1&color=fff`;
                    }}
                />
                {wide && (
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-semibold text-slate-800 dark:text-slate-200 truncate font-sans">
                            {auth.user.name}
                        </p>
                        <p className="text-[10px] text-slate-500 dark:text-slate-400 truncate uppercase font-bold tracking-tight">
                            {auth.user.roles[0]?.name || "Staff"}
                        </p>
                    </div>
                )}
            </div>

            {/* Navigation */}
            <nav className="flex-1 overflow-y-auto py-3 scrollbar-none font-sans">
                {menuNavigation.map((section, index) => {
                    // Permission loose comparison (= true) supaya permission
                    // yang dihitung dinamis ikut terbaca.
                    const hasPermission = section.details.some(
                        (detail) => detail.permissions == true
                    );

                    if (!hasPermission) return null;

                    if (section.collapsible) {
                        return (
                            <MenuSection
                                key={index}
                                section={section}
                                index={index}
                                isOpen={openedSections.has(index)}
                                onToggle={() => toggleSection(index)}
                                sidebarOpen={wide}
                                onNavigate={onNavigate}
                            />
                        );
                    }

                    return (
                        <div key={index} className="mb-4">
                            {/* Section Title */}
                            {wide && (
                                <div className="px-5 py-2">
                                    <span className="text-[10px] font-black uppercase tracking-[0.15em] text-slate-500 dark:text-slate-400">
                                        {section.title}
                                    </span>
                                </div>
                            )}

                            {/* Menu Items */}
                            <div className={wide ? "px-3" : "flex flex-col items-center px-2"}>
                                {section.details.map((detail, idx) => {
                                    // Filter menu berdasarkan permission (loose comparison)
                                    if (detail.permissions != true) return null;

                                    if (Object.prototype.hasOwnProperty.call(detail, "subdetails")) {
                                        return (
                                            <LinkItemDropdown
                                                key={idx}
                                                title={detail.title}
                                                icon={detail.icon}
                                                data={detail.subdetails}
                                                access={detail.permissions}
                                                sidebarOpen={wide}
                                                onNavigate={onNavigate}
                                            />
                                        );
                                    }

                                    return (
                                        <LinkItem
                                            key={idx}
                                            title={detail.title}
                                            icon={detail.icon}
                                            href={detail.href}
                                            access={detail.permissions}
                                            sidebarOpen={wide}
                                            onNavigate={onNavigate}
                                        />
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </nav>

            {/* Version/Footer */}
            {wide && (
                <div className="p-4 shrink-0 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50">
                    <p className="text-[9px] font-bold text-slate-500 dark:text-slate-400 text-center uppercase tracking-widest">
                        LakuPOS v2.1
                    </p>
                </div>
            )}
        </React.Fragment>
    );
}

export default function Sidebar({ sidebarOpen, mobileOpen, onCloseMobile }) {
    const { url } = usePage();

    // Drawer harus menutup sendiri begitu halaman berganti. Tutupannya
    // dilakukan lewat state layout, bukan lewat efek di sini supaya
    // Sidebar tetap tidak menyimpan kebenaran ganda.
    useEffect(() => {
        if (mobileOpen && onCloseMobile) onCloseMobile();
    }, [url]); // eslint-disable-line react-hooks/exhaustive-deps

    // Menu di dalam drawer selalu menampilkan label. `onNavigate` dipakai
    // supaya mengetuk item langsung menutup drawer, tanpa menunggu
    // navigasi selesai.
    const handleNavigate = useCallback(() => {
        if (onCloseMobile) onCloseMobile();
    }, [onCloseMobile]);

    return (
        <React.Fragment>
            {/* Layar lebar: sidebar menempel di kiri, lebarnya dikendalikan
                pengguna lewat tombol di Navbar. */}
            <aside
                className={`
                    ${sidebarOpen ? "w-[260px]" : "w-[80px]"}
                    hidden md:flex md:flex-col h-screen sticky top-0 shrink-0
                    border-r border-slate-200 dark:border-slate-800
                    bg-white dark:bg-slate-900
                    transition-all duration-300 ease-in-out
                `}
                aria-label="Menu utama"
            >
                <SidebarBody sidebarOpen={sidebarOpen} mode="desktop" />
            </aside>

            {/* LAYAR SEMPIT: drawer.
                Sebelumnya seluruh sidebar disembunyikan di bawah 768px
                (`hidden md:flex`) dan tombol bukaNYA juga disembunyikan,
                jadi di HP tidak ada cara navigating sama sekali. Drawer ini
                yang menggantikannya. */}
            <div
                className={`md:hidden fixed inset-0 z-50 ${
                    mobileOpen ? "" : "pointer-events-none"
                }`}
                aria-hidden={!mobileOpen}
            >
                {/* Latar gelap. Sengaja hanya aktif saat drawer terbuka supaya
                    tidak menahan sentuhan halaman di belakang saat tertutup. */}
                <div
                    onClick={onCloseMobile}
                    className={`absolute inset-0 bg-slate-900/60 backdrop-blur-[2px] transition-opacity duration-300 ${
                        mobileOpen ? "opacity-100" : "opacity-0"
                    }`}
                />

                <aside
                    role="dialog"
                    aria-modal={mobileOpen}
                    aria-label="Menu utama"
                    className={`
                        absolute inset-y-0 left-0 flex flex-col
                        w-[280px] max-w-[85vw]
                        bg-white dark:bg-slate-900
                        border-r border-slate-200 dark:border-slate-800
                        shadow-2xl
                        transition-transform duration-300 ease-out
                        ${mobileOpen ? "translate-x-0" : "-translate-x-full"}
                    `}
                >
                    <SidebarBody
                        sidebarOpen
                        mode="drawer"
                        onNavigate={handleNavigate}
                    />

                    {/* Tombol tutup. Jaruh sentuhnya 44px supaya enak ditekan
                        dengan jari, bukan cuma ikon 20px di pojok. */}
                    <button
                        type="button"
                        onClick={onCloseMobile}
                        aria-label="Tutup menu"
                        className="absolute top-3.5 right-2 z-10 h-9 w-9 flex items-center justify-center rounded-lg text-slate-500 dark:text-slate-400 hover:text-slate-700 hover:bg-slate-100 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            className="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </aside>
            </div>
        </React.Fragment>
    );
}
