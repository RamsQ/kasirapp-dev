import React from "react";
import { Link, usePage } from "@inertiajs/react";
import { isActivePath } from "@/Utils/ActivePath";

export default function LinkItem({
    href,
    icon,
    access,
    title,
    sidebarOpen,
    // Dipakai Sidebar untuk menutup drawer setelah menu ditekan. Dipisah
    // dari `...props` supaya tidak ikut diteruskan ke <Link> sebagai
    // atribut DOM yang tidak dikenal.
    onNavigate,
    ...props
}) {
    const { url } = usePage();
    const { auth } = usePage().props;

    const isActive = isActivePath(url, href);
    const canAccess = auth.super === true || access === true;

    if (!canAccess) return null;

    const baseClasses = `
        flex items-center gap-3
        transition-all duration-200
        text-slate-600 dark:text-slate-400
    `;

    const activeClasses = isActive
        ? "bg-primary-50 dark:bg-primary-950/50 text-primary-700 dark:text-primary-400 border-l-[3px] border-primary-500"
        : "hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-slate-200 border-l-[3px] border-transparent";

    if (sidebarOpen) {
        return (
            <Link
                href={href}
                onClick={onNavigate}
                className={`${baseClasses} ${activeClasses} px-4 py-2.5 text-sm font-medium`}
                aria-current={isActive ? "page" : undefined}
                {...props}
            >
                <span
                    className={
                        isActive ? "text-primary-600 dark:text-primary-400" : ""
                    }
                >
                    {icon}
                </span>
                <span className="truncate">{title}</span>
            </Link>
        );
    }

    // Collapsed sidebar
    return (
        <Link
            href={href}
            onClick={onNavigate}
            className={`
                w-full flex justify-center py-3
                transition-all duration-200
                ${
                    isActive
                        ? "text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-950/50"
                        : "text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                }
            `}
            title={title}
            aria-current={isActive ? "page" : undefined}
            {...props}
        >
            {icon}
        </Link>
    );
}
