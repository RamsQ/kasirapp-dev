import React from "react";

/**
 * Wadah satu panel grafik.
 *
 * Warnanya mengikuti tema aplikasi lewat kelas `dark:`, bukan dikunci gelap.
 *
 * Versi sebelumnya memakai slate-900 terus-menerus dengan alasan "grafik
 * lebih enak dibaca gelap". Itu keliru: panelnya ikut terkunci gelap kalau
 * pun aplikasi-nya terang, sehingga"Who pun yang memakai mode terang akan
 * melihat halaman analitik yang berbeda dari seluruh aplikasi lain, dan
 * menganggap itu rusak. Latar panel sekarang memakai warna permukaan yang
 * sama dengan halaman lain, dan yang menentukan keterbacaan grafik adalah
 * warna serinya (lihat useChartTheme), bukan latar kartunya.
 */
export default function ChartCard({
    title,
    subtitle,
    icon: Icon,
    height = 280,
    children,
    action,
    footer,
}) {
    return (
        <section className="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <header className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                        {Icon ? (
                            <Icon
                                size={16}
                                strokeWidth={1.8}
                                className="shrink-0 text-slate-400 dark:text-slate-500"
                            />
                        ) : null}
                        <span className="truncate">{title}</span>
                    </h2>
                    {subtitle ? (
                        <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-500">{subtitle}</p>
                    ) : null}
                </div>
                {action}
            </header>

            <div className="flex-1 px-2 py-3" style={{ minHeight: height }}>
                {children}
            </div>

            {footer ? (
                <footer className="border-t border-slate-200 px-4 py-2 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-500">
                    {footer}
                </footer>
            ) : null}
        </section>
    );
}

/**
 * Placeholder yang jujur untuk panel yang tidak punya data.
 *
 * Grafik kosong tanpa keterangan membuat orang mengira panelnya rusak.
 * Kalau memang tidak ada transaksi, itu informasi yang berguna, jadi
 * ditulis apa adanya beserta penyebabnya.
 */
export function EmptyPanel({ message, height = 280 }) {
    return (
        <div
            className="flex flex-col items-center justify-center gap-1 text-center"
            style={{ minHeight: height }}
        >
            <p className="text-sm text-slate-500 dark:text-slate-400">{message}</p>
            <p className="text-xs text-slate-400 dark:text-slate-600">Tidak ada data pada periode ini.</p>
        </div>
    );
}
