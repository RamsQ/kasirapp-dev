import React, { useEffect, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import {
    IconChevronDown,
    IconChevronUp,
    IconCornerDownRight,
} from "@tabler/icons-react";
import { isActivePath } from "@/Utils/ActivePath";

/**
 * Menu bercabang di sidebar.
 *
 * Dua perbaikan yang masuk di sini:
 *
 * 1. Grup aktif. Sebelumnya penanda halaman aktif membandingkan
 *    `url === data.href`, padahal `url` itu path (`/dashboard/products`)
 *    dan `data.href` URL lengkap dari Ziggy. Keduanya tidak akan pernah
 *    sama, jadi tidak ada anak menu yang pernah tersorot. Sekarang lewat
 *    `isActivePath`.
 *
 * 2. gruppo terbuka sendiri. Kalau kasir sedang di halaman anak tapi grupnya
 *    tertutup, dia mengira menunya hilang — persis masalah yang sudah
 *    persis di Sidebar untuk grup yang bisa dilipat. Aturan yang sama
 *    diterapkan di sini: grup yang memuat halaman aktif selalu terbuka.
 *
 * Kode ini sebelumnya punya empat cabang yang isinya nyaris sama, dengan
 * satu-satunya beda `auth.super === true` atau tidak.selesai
 */
export default function LinkItemDropdown({
    icon,
    title,
    data,
    access,
    sidebarOpen,
    // Dipakai Sidebar untuk menutup drawer setelah item ditekan.
    onNavigate,
    ...props
}) {
    const { url } = usePage();
    const { auth } = usePage().props;

    const canAccess = auth.super === true || access === true;

    // Hanya anak yang boleh diakses yang dihitung, supaya grup tidak
    // terbuka karena anak yang kebetulan tidak bisa dibuka.
    const visible = (data || []).filter((child) => child.permissions === true);

    const containsActive = visible.some((child) => isActivePath(url, child.href));

    const [isOpen, setIsOpen] = useState(containsActive);

    useEffect(() => {
        if (containsActive) setIsOpen(true);
    }, [containsActive]);

    if (!canAccess) return null;

    if (visible.length === 0) return null;

    const rowClasses = "min-w-full flex items-center gap-x-3.5 py-3 px-5 text-sm font-medium text-gray-500 hover:bg-slate-50 hover:text-gray-900 dark:text-gray-500 dark:hover:bg-slate-800 dark:hover:text-gray-100 transition-colors";
    const childClasses = (active) =>
        `${rowClasses} ${
            active
                ? "border-r-2 border-r-primary-400 bg-primary-50 text-primary-700 dark:border-r-primary-500 dark:bg-primary-950/50 dark:text-primary-300"
                : ""
        }`;

    // Sidebar sempit (80px): tidak ada ruang untuk teks, jadi hanya ikon
    // dan chevron yang ditampilkan. Lebar tetap hidup di dalam drawer HP
    // karena drawer's `sidebarOpen` selalu true.
    if (!sidebarOpen) {
        return (
            <div className="w-full">
                <button
                    type="button"
                    onClick={() => setIsOpen((v) => !v)}
                    aria-expanded={isOpen}
                    aria-label={title}
                    title={title}
                    className="min-w-full flex justify-center py-3 hover:cursor-pointer text-gray-500 hover:bg-slate-100 hover:text-gray-900 dark:text-gray-500 dark:hover:bg-slate-800 dark:hover:text-gray-100 transition-colors"
                >
                    {isOpen ? (
                        <IconChevronDown size={20} strokeWidth={1.5} />
                    ) : (
                        icon
                    )}
                </button>

                {isOpen &&
                    visible.map((child, i) => (
                        <Link
                            key={i}
                            href={child.href}
                            onClick={onNavigate}
                            title={child.title}
                            aria-current={
                                isActivePath(url, child.href)
                                    ? "page"
                                    : undefined
                            }
                            className={`
                                min-w-full flex justify-center py-3 hover:cursor-pointer transition-colors
                                ${
                                    isActivePath(url, child.href)
                                        ? "text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-950/50"
                                        : "text-gray-500 hover:bg-slate-100 hover:text-gray-900 dark:text-gray-500 dark:hover:bg-slate-800 dark:hover:text-gray-100"
                                }
                            `}
                            {...props}
                        >
                            {child.icon}
                        </Link>
                    ))}
            </div>
        );
    }

    return (
        <div className="w-full">
            <button
                type="button"
                onClick={() => setIsOpen((v) => !v)}
                aria-expanded={isOpen}
                className={`${rowClasses} justify-between hover:cursor-pointer`}
            >
                <div className="flex items-center gap-x-3.5 min-w-0">
                    {icon}
                    <span className="truncate">{title}</span>
                </div>
                {isOpen ? (
                    <IconChevronUp size={18} strokeWidth={1.5} className="shrink-0" />
                ) : (
                    <IconChevronDown size={18} strokeWidth={1.5} className="shrink-0" />
                )}
            </button>

            {isOpen &&
                visible.map((child, i) => (
                    <Link
                        key={i}
                        href={child.href}
                        onClick={onNavigate}
                        aria-current={
                            isActivePath(url, child.href) ? "page" : undefined
                        }
                        className={`${childClasses(
                            isActivePath(url, child.href)
                        )} truncate`}
                        {...props}
                    >
                        <IconCornerDownRight
                            size={18}
                            strokeWidth={1.5}
                            className="shrink-0"
                        />
                        {child.title}
                    </Link>
                ))}
        </div>
    );
}
