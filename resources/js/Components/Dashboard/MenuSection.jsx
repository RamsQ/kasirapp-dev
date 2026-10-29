import React from "react";
import { IconChevronDown, IconChevronRight } from "@tabler/icons-react";
import LinkItem from "@/Components/Dashboard/LinkItem";
import LinkItemDropdown from "@/Components/Dashboard/LinkItemDropdown";

/**
 * Grup menu yang judulnya bisa dibuka dan ditutup.
 *
 * Dipakai untuk grup yang isinya panjang (Data Management, Inventory
 * Intelligence, User Management, Pengaturan) supaya sidebar tidak memanjang
 * ke bawah tanpa henti.
 *
 * Dua aturan yang dijaga di sini:
 *  1. Grup yang sedang berisi halaman aktif SELALU terbuka. Kalau tidak,
 *     kasir berada di halaman Resep tapi tidak melihat bahwa grupnya berisi
 *     Resep, lalu mengira menunya hilang.
 *  2. Grup tanpa permission yang boleh diakses tidak dirender sama sekali,
 *     bukan cuma disembunyikan isinya. Karena judulnya yang jadi tombol, kalau
 *     judulnya tetap terlihat, kasir akan mengira ada menu yang tidak bisa
 *     dibuka.
 */
export default function MenuSection({ section, index, isOpen, onToggle, sidebarOpen }) {
    const visible = section.details.filter((detail) => detail.permissions == true);

    if (visible.length === 0) return null;

    return (
        <div className="mb-4">
            {sidebarOpen ? (
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={isOpen}
                    className="w-full px-5 py-2 flex items-center justify-between gap-2 group"
                >
                    <span
                        className={`text-[10px] font-black uppercase tracking-[0.15em] transition-colors ${
                            isOpen
                                ? "text-slate-600 dark:text-slate-300"
                                : "text-slate-400 group-hover:text-slate-500"
                        }`}
                    >
                        {section.title}
                    </span>
                    <IconChevronRight
                        size={14}
                        strokeWidth={2.5}
                        className={`shrink-0 text-slate-300 dark:text-slate-400 transition-transform ${
                            isOpen ? "rotate-90" : ""
                        }`}
                    />
                </button>
            ) : (
                // Sidebar sempit tidak menampilkan judul grup, jadi butuh
                // tombol kecil sendiri supaya grupnya tetap bisa dibuka.
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={isOpen}
                    title={section.title}
                    className="w-full py-2 flex justify-center text-slate-300 dark:text-slate-400 hover:text-slate-500 transition-colors"
                >
                    <IconChevronDown
                        size={14}
                        strokeWidth={2.5}
                        className={`transition-transform ${isOpen ? "" : "-rotate-90"}`}
                    />
                </button>
            )}

            {isOpen && (
                <div className={sidebarOpen ? "px-3" : "flex flex-col items-center px-2"}>
                    {visible.map((detail, idx) => {
                        if (Object.prototype.hasOwnProperty.call(detail, "subdetails")) {
                            return (
                                <LinkItemDropdown
                                    key={idx}
                                    title={detail.title}
                                    icon={detail.icon}
                                    data={detail.subdetails}
                                    access={detail.permissions}
                                    sidebarOpen={sidebarOpen}
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
                                sidebarOpen={sidebarOpen}
                            />
                        );
                    })}
                </div>
            )}
        </div>
    );
}
