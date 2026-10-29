import React, { useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import ChartCard, { EmptyPanel } from "./ChartCard";
import { useTheme } from "@/Context/ThemeSwitcherContext";
import {
    describeFreshness,
    formatCompactCurrency,
    formatCurrency,
    formatDelta,
    formatNumber,
    formatPercent,
    deltaTone,
    paymentColor,
    paymentLabel,
    useAutoRefresh,
    useChartTheme,
    useChartAxis,
    useDeltaColor,
    useIdleBarColor,
} from "./charts";
import {
    ResponsiveContainer,
    ComposedChart,
    Area,
    Line,
    Bar,
    BarChart,
    PieChart,
    Pie,
    Cell,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ReferenceLine,
} from "recharts";
import {
    IconAlertTriangle,
    IconCalendar,
    IconChartBar,
    IconChartDots,
    IconChartLine,
    IconChartPie,
    IconChevronDown,
    IconClockHour4,
    IconInfoCircle,
    IconPackage,
    IconPercentage,
    IconPresentationAnalytics,
    IconReceipt,
    IconTag,
    IconTicket,
    IconTrendingUp,
    IconUsers,
    IconWallet,
} from "@tabler/icons-react";

/**
 * Kartu KPI dengan pembanding periode sebelumnya.
 *
 * Warna tanda naik/turun diambil lewat useDeltaColor, bukan kelas Tailwind
 * yang terkunci. Hijau emerald-400 hampir tak terlihat di atas kartu putih,
 * jadi di mode terang kartu ini akan terlihat seperti tidak berubah sama
 * sekali.
 */
function KpiCard({ label, value, delta, icon: Icon, tone, hint }) {
    const deltaInfo = formatDelta(delta);
    const deltaColors = useDeltaColor();

    return (
        <div className="rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    {label}
                </span>
                {Icon ? (
                    <Icon
                        size={16}
                        strokeWidth={1.8}
                        className="shrink-0 text-slate-400"
                    />
                ) : null}
            </div>

            <p className="mt-1.5 truncate text-xl font-semibold text-slate-800 dark:text-slate-50">
                {value}
            </p>

            <p
                className="mt-1 text-xs font-medium"
                style={{ color: deltaColors[deltaTone(tone, delta)] }}
            >
                {deltaInfo.text}
            </p>

            {hint ? (
                <p className="mt-0.5 text-[11px] text-slate-400">{hint}</p>
            ) : null}
        </div>
    );
}

/** Tooltip yang menampilkan angka rupiah dengan rapi, mengikuti tema. */
function MoneyTooltip({ active, payload, label, formatter }) {
    const theme = useChartTheme();
    const { darkMode } = useTheme();

    if (!active || !payload?.length) return null;

    return (
        <div
            className="rounded-lg border px-3 py-2 text-xs shadow-xl"
            style={{ background: theme.tooltipBg, borderColor: theme.tooltipBorder }}
        >
            <p className="mb-1 font-semibold text-slate-700 dark:text-slate-200">{label}</p>
            {payload.map((entry) => (
                <p key={entry.dataKey ?? entry.name} className="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                    <span
                        className="inline-block h-2 w-2 shrink-0 rounded-full"
                        style={{ background: entry.color || entry.payload?.fill }}
                    />
                    <span>{entry.name}</span>
                    <span className="ml-auto font-medium text-slate-800 dark:text-slate-100">
                        {formatter
                            ? formatter(entry.value, entry)
                            : formatCurrency(entry.value)}
                    </span>
                </p>
            ))}
        </div>
    );
}

/** Sumbu Y dengan label rupiah ringkas supaya tidak saling menimpa. */
function MoneyAxis({ tickFormatter = formatCompactCurrency }) {
    const axis = useChartAxis();

    return (
        <YAxis
            tick={{ fill: axis.tick, fontSize: axis.fontSize }}
            axisLine={false}
            tickLine={false}
            width={64}
            tickFormatter={tickFormatter}
        />
    );
}

function XAxisLabel({ dataKey = "label" }) {
    const axis = useChartAxis();

    return (
        <XAxis
            dataKey={dataKey}
            tick={{ fill: axis.tick, fontSize: axis.fontSize }}
            axisLine={false}
            tickLine={false}
            interval="preserveStartEnd"
            angle={-35}
            textAnchor="end"
            height={52}
        />
    );
}

/** Garis grid tipis dengan warna yang sesuai tema. */
function Grid({ horizontal = false }) {
    const axis = useChartAxis();

    return <CartesianGrid stroke={axis.grid} strokeDasharray="3 3" vertical={!horizontal} horizontal={horizontal} />;
}

/**
 * Tooltip kecil untuk panel yang isinya bukan uang semua, misalnya panel jam
 * yang menampilkan jumlah pesanan sekaligus omzet jam itu.
 */
function PanelTooltip({ active, payload, label, primary, secondary }) {
    const theme = useChartTheme();

    if (!active || !payload?.length) return null;

    return (
        <div
            className="rounded-lg border px-3 py-2 text-xs shadow-xl"
            style={{ background: theme.tooltipBg, borderColor: theme.tooltipBorder }}
        >
            <p className="font-semibold text-slate-700 dark:text-slate-200">{label}</p>
            {primary ? <p className="text-slate-600 dark:text-slate-300">{primary(payload)}</p> : null}
            {secondary ? <p className="text-slate-800 dark:text-slate-100">{secondary(payload)}</p> : null}
        </div>
    );
}

const GRANULARITY_LABELS = {
    auto: "Otomatis",
    daily: "Harian",
    weekly: "Mingguan",
    monthly: "Bulanan",
};

/**
 * Panel Promosi.
 *
 * Yang ditampilkan HANYA yang bisa dibuktikan dari data yang ada:
 *  - berapa total diskon yang diberikan, dan persentasenya dari omzet
 *    SEBELUM diskon (bukan dari omzet yang sudah dikurangi diskon)
 *  - pemecahan diskon otomatis vs voucher
 *  - perbandingan transaksi berpromo vs tanpa promo
 *  - peringkat per kode voucher
 *
 * Yang SENGAJA tidak ditampilkan: peringkat per aturan diskon, dan
 * klaim "promo menaikkan omzet X%". Alasannya ada di AnalyticsService.
 * `transactions` tidak menyimpan `discount_id`, jadi transaksi yang dapat
 * diskon global tidak bisa ditelusuri ke aturan mana; dan rata-rata order
 * kelompok berpromo memang lebih tinggi bukan karena promo, tapi karena
 * orang yang pakai promo sudah tahu tokonya.
 */
function PromoPanel({ promo }) {
    const theme = useChartTheme();

    if (!promo || promo.total_orders === 0) {
        return (
            <ChartCard title="Promosi" height={240}>
                <EmptyPanel message="Belum ada transaksi pada periode ini." height={200} />
            </ChartCard>
        );
    }

    /*
     * Backend mengirim kunci SNAKE_CASE: `with_promo`, bukan `withPromo`.
     *
     * Versi sebelumnya menulis `const { withPromo } = promo`. Kunci itu tidak
     * ada di props, jadi hasilnya `undefined` tanpa error dan tanpa
     * peringatan. Baru ketahuan saat `withPromo.orders` dirender dan
     * melempar "Cannot read properties of undefined". Karena halaman
     * tidak punya error boundary, seluruh halaman ikut hilang, bukan cuma
     * panelnya.
     *
     * Setiap kelompok juga diberi nilai cadangan, supaya kelompok yang
     * tidak ada (misal tidak ada satu pun transaksi tanpa promo) tidak
     * menjatuhkan halaman.
     */
    const kosong = {
        orders: 0,
        discount: 0,
        revenue: 0,
        net_revenue: 0,
        hpp: 0,
        gross_profit: 0,
        average_order: 0,
        average_discount: 0,
    };

    const withPromo = { ...kosong, ...(promo.with_promo ?? {}) };
    const withoutPromo = { ...kosong, ...(promo.without_promo ?? {}) };
    const adaDiskon = promo.total_discount > 0;
    const vouchers = Array.isArray(promo.vouchers) ? promo.vouchers : [];

    /*
     * Nilai perbandingan dihitung sekali di sini, di luar JSX, lalu yang
     * dirender hanya teks yang sudah pasti ada. Versi sebelumnya menaruh
     * objek kelompok di dalam array yang berada di dalam callback .map()
     * di tengah JSX, sehingga `group` dibaca dari scope yang salah.
     */
    const perbandingan = [
        {
            label: 'Transaksi',
            withValue: formatNumber(withPromo.orders),
            withoutValue: formatNumber(withoutPromo.orders),
        },
        {
            label: 'Rata-rata order',
            withValue: formatCurrency(withPromo.average_order),
            withoutValue: formatCurrency(withoutPromo.average_order),
        },
        {
            label: 'Laba kotor total',
            withValue: formatCurrency(withPromo.gross_profit),
            withoutValue: formatCurrency(withoutPromo.gross_profit),
        },
    ];

    const pecahanDiskon = [
        { nama: 'Otomatis', nilai: formatCurrency(promo.global_discount), warna: theme.hpp },
        { nama: 'Voucher', nilai: formatCurrency(promo.voucher_discount), warna: theme.gross },
    ];

    return (
        <div className="space-y-3">
            {/* KPI diskon */}
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard
                    label="Total Diskon"
                    value={formatCurrency(promo.total_discount)}
                    delta={null}
                    tone="hpp"
                    icon={IconTag}
                    hint={`${formatPercent(promo.discount_percent_of_revenue)} dari omzet sebelum diskon`}
                />
                <KpiCard
                    label="Diskon Otomatis"
                    value={formatCurrency(promo.global_discount)}
                    delta={null}
                    tone="hpp"
                    icon={IconPercentage}
                    hint="Dari aturan promo global"
                />
                <KpiCard
                    label="Diskon Voucher"
                    value={formatCurrency(promo.voucher_discount)}
                    delta={null}
                    tone="hpp"
                    icon={IconTicket}
                    hint="Dari kode voucher pelanggan"
                />
                <KpiCard
                    label="Transaksi Berpromo"
                    value={`${formatNumber(promo.promo_orders)} / ${formatNumber(promo.total_orders)}`}
                    delta={null}
                    tone="net_revenue"
                    icon={IconReceipt}
                    hint="Transaksi yang dapat diskon"
                />
            </div>

            {!adaDiskon ? (
                <ChartCard title="Belum ada diskon di periode ini" height={200}>
                    <EmptyPanel
                        message="Tidak ada transaksi yang memakai diskon atau voucher."
                        height={160}
                    />
                </ChartCard>
            ) : (
                <>
                    <div className="grid gap-3 lg:grid-cols-2">
                        {/* Perbandingan kelompok */}
                        <ChartCard
                            title="B promo vs Tanpa Promo"
                            subtitle="Perbandingan dua kelompok transaksi. Bukan bukti promo berhasil: orang yang memakai promo sudah tahu tokonya, jadi rata-rata order-nya memang lebih tinggi sejak awal."
                            icon={IconChartBar}
                            height={300}
                        >
                            <div className="px-2 space-y-3">
                                {perbandingan.map((row) => (
                                    <div key={row.label}>
                                        <div className="mb-1 text-[10px] font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            {row.label}
                                        </div>
                                        <div className="grid grid-cols-2 gap-2">
                                            <div className="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-800">
                                                <div className="text-[10px] uppercase text-slate-400">
                                                    Berpromo
                                                </div>
                                                <div className="text-sm font-black text-cyan-600 dark:text-cyan-400">
                                                    {row.withValue}
                                                </div>
                                            </div>
                                            <div className="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-800">
                                                <div className="text-[10px] uppercase text-slate-400">
                                                    Tanpa promo
                                                </div>
                                                <div className="text-sm font-black text-slate-600 dark:text-slate-300">
                                                    {row.withoutValue}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </ChartCard>

                        {/* Pecahan diskon */}
                        <ChartCard
                            title="Ke Mana Diskon Mengusband"
                            subtitle="Diskon otomatis dari aturan global, disandingkan dengan diskon dari kode voucher."
                            icon={IconChartPie}
                            height={300}
                        >
                            <ResponsiveContainer width="100%" height={220}>
                                <PieChart>
                                    <Pie
                                        data={[
                                            { name: "Otomatis", value: promo.global_discount },
                                            { name: "Voucher", value: promo.voucher_discount },
                                        ].filter((row) => row.value > 0)}
                                        dataKey="value"
                                        nameKey="name"
                                        innerRadius={52}
                                        outerRadius={82}
                                        paddingAngle={2}
                                        stroke="none"
                                        isAnimationActive={false}
                                    >
                                        {pecahanDiskon.map((row, index) => (
                                            <Cell
                                                key={row.nama}
                                                fill={[theme.hpp, theme.gross][index]}
                                            />
                                        ))}
                                    </Pie>
                                    <Tooltip
                                        content={({ active, payload }) => (
                                            <PanelTooltip
                                                active={active}
                                                payload={payload}
                                                label={payload?.[0]?.name}
                                                secondary={(rows) => formatCurrency(rows[0].value)}
                                            />
                                        )}
                                    />
                                </PieChart>
                            </ResponsiveContainer>

                            <ul className="mt-1 space-y-1 px-3">
                                {pecahanDiskon.map((row) => (
                                    <li key={row.nama} className="flex items-center gap-2 text-xs">
                                        <span
                                            className="h-2 w-2 shrink-0 rounded-full"
                                            style={{ background: row.warna }}
                                        />
                                        <span className="text-slate-500 dark:text-slate-400">{row.nama}</span>
                                        <span className="ml-auto text-slate-700 dark:text-slate-200">
                                            {row.nilai}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </ChartCard>
                    </div>

                    {/* Voucher */}
                    <ChartCard
                        title="Voucher yang Terpakai"
                        subtitle="Satu-satunya promo yang bisa dilacak per aturannya, karena kode vouchernya tersimpan di setiap transaksi."
                        icon={IconTicket}
                        height={300}
                    >
                        {vouchers.length ? (
                            <div className="overflow-x-auto px-2">
                                <table className="min-w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 dark:border-slate-800">
                                            <th className="py-2 pr-4 font-medium">Kode</th>
                                            <th className="py-2 pr-4 text-right font-medium">Transaksi</th>
                                            <th className="py-2 pr-4 text-right font-medium">Diskon Diberi</th>
                                            <th className="py-2 pr-4 text-right font-medium">Omzet Bersih</th>
                                            <th className="py-2 text-right font-medium">Rata-rata</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {vouchers.map((voucher) => (
                                            <tr
                                                key={voucher.code}
                                                className="border-b border-slate-200/70 last:border-0 dark:border-slate-800/60"
                                            >
                                                <td className="py-2 pr-4 font-black text-slate-800 dark:text-slate-200">
                                                    {voucher.code}
                                                </td>
                                                <td className="py-2 pr-4 text-right text-slate-600 dark:text-slate-300">
                                                    {formatNumber(voucher.orders)}
                                                </td>
                                                <td className="py-2 pr-4 text-right text-rose-600 dark:text-rose-400">
                                                    {formatCurrency(voucher.discount)}
                                                </td>
                                                <td className="py-2 pr-4 text-right text-slate-600 dark:text-slate-300">
                                                    {formatCurrency(voucher.net_revenue)}
                                                </td>
                                                <td className="py-2 text-right text-slate-600 dark:text-slate-300">
                                                    {formatCurrency(voucher.average_order)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <EmptyPanel message="Belum ada kode voucher yang terpakai." />
                        )}
                    </ChartCard>

                    {/* Batasan data */}
                    <div className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        <IconInfoCircle size={18} className="mt-0.5 shrink-0" />
                        <div>
                            <p className="font-medium">Kenapa tidak ada peringkat per aturan promo?</p>
                            <p className="mt-0.5 text-xs text-amber-700 dark:text-amber-400/80">
                                Transaksi hanya menyimpan nominal diskon, bukan aturan promo mana yang
                                memberikannya. Jadi transaksi yang dapat diskon global tidak bisa
                                ditelusuri ke aturan tertentu. Voucher bisa, karena kodenya tersimpan
                                di tiap transaksi.
                            </p>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

export default function AnalyticsIndex({
    period,
    comparison,
    kpis,
    deltas,
    trend,
    topProducts,
    productMargins,
    byCategory,
    byPaymentMethod,
    byHour,
    byWeekday,
    byCashier,
    lowStock,
    promo,
    empty,
}) {
    const [customStart, setCustomStart] = useState(period.start);
    const [customEnd, setCustomEnd] = useState(period.end);
    const [showCustom, setShowCustom] = useState(period.preset === "custom");

    // Tab: ringkasan toko, atau analisis promosi.
    //
    // Dipisah supaya halaman tidak tumbuh jadi satu layar panjang yang
    // Ninth panjang saat di-scroll. Periode, tema, dan auto-refresh dipakai
    // keduanya, jadi pindah tab tidak memuat ulang data.
    const [tab, setTab] = useState("ringkasan");

    // Warna grafik, sumbu, dan delta diambil dari hook tema.
    //
    // Hook harus dipanggil di level komponen, bukan di dalam JSX, karena
    // mereka memanggil useContext. Nilai yang dikembalikan ikut berubah saat
    // tema diganti, dan itulah yang membuat recharts menggambar ulang SVG-nya
    // dengan warna baru. Kalau warnanya ditulis sebagai hex di dalam JSX,
    // grafiknya akan tetap memakai warna tema lama sampai halaman dimuat ulang.
    const { darkMode } = useTheme();
    const theme = useChartTheme();
    const axis = useChartAxis();
    const idleBar = useIdleBarColor();
    const autoRefresh = useAutoRefresh(period.preset);

    /**
     * Minta data periode baru.
     *
     * preserveState supaya scroll dan state komponen lain tidak hilang,
     * replace supaya tombol back tidak menumpuk satu entri per klik preset.
     */
    const go = (params) =>
        router.get(route("reports.analytics"), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const trendWithShares = useMemo(
        () =>
            trend.map((row) => ({
                ...row,
                net_label: formatCompactCurrency(row.net_revenue),
            })),
        [trend]
    );

    const busiestHours = useMemo(
        () => [...byHour].sort((a, b) => b.revenue - a.revenue).slice(0, 3),
        [byHour]
    );

    // 8 kategori teratas. Backend sudah mengirim kolom `name` untuk kategori,
    // jadi tidak perlu dialih-alihkan ke `title` seperti pada produk.
    const categoryData = useMemo(() => byCategory.slice(0, 8), [byCategory]);

    return (
        <DashboardLayout title="Analitik Grafis">
            <Head title="Analitik Grafis - LakuPOS" />

            <div className="space-y-4">
                {/* ---------- PEMILIH PERIODE ---------- */}
                <div className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex flex-wrap gap-1">
                            {period.presets.map((preset) => (
                                <button
                                    key={preset.key}
                                    type="button"
                                    onClick={() => go({ preset: preset.key })}
                                    className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                        period.preset === preset.key
                                            ? "bg-cyan-50 text-cyan-700 ring-1 ring-inset ring-cyan-200 dark:bg-cyan-500/15 dark:text-cyan-300 dark:ring-cyan-500/40"
                                            : "bg-slate-100 text-slate-500 hover:bg-slate-200 hover:text-slate-700 dark:bg-slate-800/60 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                    }`}
                                >
                                    {preset.label}
                                </button>
                            ))}

                            <button
                                type="button"
                                onClick={() => setShowCustom((value) => !value)}
                                className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                    period.preset === "custom"
                                        ? "bg-cyan-50 text-cyan-700 ring-1 ring-inset ring-cyan-200 dark:bg-cyan-500/15 dark:text-cyan-300 dark:ring-cyan-500/40"
                                        : "bg-slate-100 text-slate-500 hover:bg-slate-200 hover:text-slate-700 dark:bg-slate-800/60 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                }`}
                            >
                                Kustom
                            </button>
                        </div>

                        <div className="ml-auto flex items-center gap-2">
                            <span className="text-xs text-slate-500 dark:text-slate-400">Granularitas</span>
                            <div className="relative">
                                <select
                                    value={period.granularity === "auto" ? "auto" : period.granularity}
                                    onChange={(event) => go({
                                        preset: period.preset,
                                        start_date: period.start,
                                        end_date: period.end,
                                        granularity: event.target.value,
                                    })}
                                    className="appearance-none rounded-md border-slate-300 bg-white py-1.5 pl-3 pr-8 text-xs text-slate-700 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:focus:border-cyan-500"
                                >
                                    {Object.entries(GRANULARITY_LABELS).map(([key, label]) => (
                                        <option key={key} value={key}>{label}</option>
                                    ))}
                                </select>
                                <IconChevronDown
                                    size={14}
                                    className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-400"
                                />
                            </div>
                        </div>
                    </div>

                    {showCustom ? (
                        <div className="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-200 pt-3 dark:border-slate-800">
                            <label className="text-xs text-slate-500 dark:text-slate-400">
                                Dari
                                <input
                                    type="date"
                                    value={customStart}
                                    max={customEnd}
                                    onChange={(event) => setCustomStart(event.target.value)}
                                    className="mt-1 block rounded-md border-slate-300 bg-white text-xs text-slate-700 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                            </label>

                            <label className="text-xs text-slate-500 dark:text-slate-400">
                                Sampai
                                <input
                                    type="date"
                                    value={customEnd}
                                    min={customStart}
                                    onChange={(event) => setCustomEnd(event.target.value)}
                                    className="mt-1 block rounded-md border-slate-300 bg-white text-xs text-slate-700 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                            </label>

                            <button
                                type="button"
                                onClick={() => go({
                                    preset: "custom",
                                    start_date: customStart,
                                    end_date: customEnd,
                                    granularity: "auto",
                                })}
                                className="rounded-md bg-cyan-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-cyan-500"
                            >
                                Terapkan
                            </button>
                        </div>
                    ) : null}

                    <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <p className="flex items-center gap-1 text-[11px] text-slate-400">
                            <IconInfoCircle size={12} />
                            {period.start} s/d {period.end} ({period.days} hari, {GRANULARITY_LABELS[period.granularity]}).
                            Pembanding: {comparison.start} s/d {comparison.end}.
                        </p>

                        {/* ---------- STATUS KESEGARAN DATA ---------- */}
                        <div className="flex items-center gap-2 text-[11px]">
                            <span className="text-slate-400">
                                Diperbarui {describeFreshness(autoRefresh.lastLoadedAt)}
                                {autoRefresh.enabled
                                    ? `, lagi ${autoRefresh.secondsLeft} dtk`
                                    : ""}
                            </span>

                            <button
                                type="button"
                                onClick={autoRefresh.reloadNow}
                                disabled={autoRefresh.refreshing}
                                className="rounded border border-slate-300 px-2 py-1 text-slate-500 transition-colors hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                            >
                                {autoRefresh.refreshing ? "Memuat..." : "Refresh"}
                            </button>

                            {autoRefresh.canAutoRefresh ? (
                                <label className="flex cursor-pointer items-center gap-1 text-slate-500 dark:text-slate-400">
                                    <input
                                        type="checkbox"
                                        checked={autoRefresh.enabled}
                                        onChange={autoRefresh.toggle}
                                        className="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-800"
                                    />
                                    Auto
                                </label>
                            ) : (
                                <span
                                    className="text-slate-400"
                                    title="Auto-refresh hanya untuk periode yang berubah terus, misal Hari Ini. Untuk periode panjang, angka 1 menit lalu hampir sama dengan sekarang."
                                >
                                    {GRANULARITY_LABELS[period.granularity]} tidak perlu auto-refresh
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                {/* ---------- PILIH TAB ---------- */}
                <div className="flex gap-1 rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
                    {[
                        { key: "ringkasan", label: "Ringkasan Toko" },
                        { key: "promo", label: "Promosi" },
                    ].map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            aria-selected={tab === item.key}
                            className={`flex-1 rounded-lg px-3 py-2 text-xs font-semibold transition-colors ${
                                tab === item.key
                                    ? "bg-primary-500 dark:bg-primary-600 text-white shadow-sm"
                                    : "text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>

                {tab === "promo" ? (
                    <PromoPanel promo={promo} />
                ) : (
                    <>
                {/* ---------- KPI ---------- */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <KpiCard
                        label="Omzet Bersih"
                        value={formatCurrency(kpis.net_revenue)}
                        delta={deltas.net_revenue}
                        tone="net_revenue"
                        icon={IconWallet}
                        hint={`${formatNumber(kpis.transaction_count)} transaksi`}
                    />
                    <KpiCard
                        label="HPP"
                        value={formatCurrency(kpis.hpp)}
                        delta={deltas.hpp}
                        tone="hpp"
                        icon={IconPackage}
                        hint={`${formatPercent(kpis.hpp_percent)} dari omzet`}
                    />
                    <KpiCard
                        label="Laba Kotor"
                        value={formatCurrency(kpis.contribution_profit)}
                        delta={deltas.contribution_profit}
                        tone="contribution_profit"
                        icon={IconChartLine}
                        hint="Omzet dikurangi HPP dan biaya gateway"
                    />
                    <KpiCard
                        label="Beban Operasional"
                        value={formatCurrency(kpis.operating_expenses)}
                        delta={deltas.operating_expenses}
                        tone="operating_expenses"
                        icon={IconReceipt}
                        hint="Tidak termasuk belanja stok dan modal"
                    />
                    <KpiCard
                        label="Laba Bersih"
                        value={formatCurrency(kpis.net_profit)}
                        delta={deltas.net_profit}
                        tone="net_profit"
                        icon={IconTrendingUp}
                        hint="Laba kotor dikurangi beban operasional"
                    />
                    <KpiCard
                        label="Margin Bersih"
                        value={formatPercent(kpis.margin_percent)}
                        delta={deltas.margin_percent}
                        tone="margin_percent"
                        icon={IconPercentage}
                        hint="Selisih dalam poin persen"
                    />
                    <KpiCard
                        label="Rata-rata Order"
                        value={formatCurrency(kpis.average_order)}
                        delta={deltas.average_order}
                        tone="average_order"
                        icon={IconReceipt}
                    />
                    <KpiCard
                        label="Item Terjual"
                        value={formatNumber(kpis.items_sold)}
                        delta={deltas.items_sold}
                        tone="items_sold"
                        icon={IconChartBar}
                    />
                </div>

                {empty ? (
                    <div className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        <IconInfoCircle size={18} className="mt-0.5 shrink-0" />
                        <div>
                            <p className="font-medium">Tidak ada transaksi lunas pada periode ini.</p>
                            <p className="mt-0.5 text-xs text-amber-700 dark:text-amber-400/80">
                                Transaksi yang masih menunggu pembayaran QRIS atau sudah direfund tidak dihitung,
                                jadi angkanya bisa 0 padahal struk tercetak. Periksa filter tanggal di atas.
                            </p>
                        </div>
                    </div>
                ) : null}

                {/* ---------- TREN ---------- */}
                <ChartCard
                    title="Tren Omzet, HPP, dan Laba"
                    subtitle="Omzet bersih sudah dipotong pajak. Garis laba dihitung dengan rumus yang sama seperti Laporan Keuangan."
                    icon={IconChartLine}
                    height={320}
                    footer={
                        <span>
                            HPP dibaca dari harga beli yang tersimpan di layer batch saat penjualan,
                            bukan dari harga beli master yang bisa saja sudah berubah.
                        </span>
                    }
                >
                    <ResponsiveContainer width="100%" height={300}>
                        <ComposedChart data={trendWithShares} margin={{ top: 8, right: 12, bottom: 4, left: 4 }}>
                            <defs>
                                <linearGradient id={`revenueFill-${darkMode ? "dark" : "light"}`} x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor={theme.revenue} stopOpacity={0.35} />
                                    <stop offset="100%" stopColor={theme.revenue} stopOpacity={0.02} />
                                </linearGradient>
                            </defs>

                            <Grid />
                            <MoneyAxis />
                            <XAxisLabel />

                            <Tooltip content={<MoneyTooltip />} />
                            <Legend
                                wrapperStyle={{ fontSize: 11, color: axis.tick, paddingTop: 4 }}
                                iconType="plainline"
                            />

                            <Area
                                type="monotone"
                                dataKey="net_revenue"
                                name="Omzet Bersih"
                                stroke={theme.revenue}
                                strokeWidth={2}
                                fill={`url(#revenueFill-${darkMode ? "dark" : "light"})`}
                            />
                            <Line
                                type="monotone"
                                dataKey="hpp"
                                name="HPP"
                                stroke={theme.hpp}
                                strokeWidth={2}
                                dot={false}
                            />
                            <Line
                                type="monotone"
                                dataKey="gross_profit"
                                name="Laba Kotor"
                                stroke={theme.gross}
                                strokeWidth={2}
                                dot={false}
                            />
                            <Line
                                type="monotone"
                                dataKey="contribution_profit"
                                name="Laba Kontribusi"
                                stroke={theme.contribution}
                                strokeWidth={2}
                                dot={false}
                            />
                            <Line
                                type="monotone"
                                dataKey="net_profit"
                                name="Laba Bersih"
                                stroke={theme.net}
                                strokeWidth={2}
                                strokeDasharray="4 3"
                                dot={false}
                            />
                        </ComposedChart>
                    </ResponsiveContainer>
                </ChartCard>

                <div className="grid gap-3 lg:grid-cols-2">
                    {/* ---------- JAM RAMAI ---------- */}
                    <ChartCard
                        title="Jam Ramai"
                        subtitle="Jumlah pesanan per jam. Dipakai untuk menentukan jam shift."
                        icon={IconClockHour4}
                        height={260}
                    >
                        <ResponsiveContainer width="100%" height={240}>
                            <BarChart data={byHour} margin={{ top: 8, right: 12, bottom: 4, left: 4 }}>
                                <Grid />
                                <YAxis
                                    tick={{ fill: axis.tick, fontSize: axis.fontSize }}
                                    axisLine={false}
                                    tickLine={false}
                                    allowDecimals={false}
                                />
                                <XAxis
                                    dataKey="label"
                                    tick={{ fill: axis.tick, fontSize: 10 }}
                                    axisLine={false}
                                    tickLine={false}
                                    interval={2}
                                />
                                <Tooltip
                                    content={({ active, payload, label }) => (
                                        <PanelTooltip
                                            active={active}
                                            payload={payload}
                                            label={label}
                                            primary={(rows) => `${formatNumber(rows[0].value)} pesanan`}
                                            secondary={(rows) => formatCurrency(
                                                byHour.find((hour) => hour.label === rows[0].payload.label)?.revenue
                                            )}
                                        />
                                    )}
                                />
                                <Bar dataKey="orders" name="Pesanan" radius={[3, 3, 0, 0]} isAnimationActive={false}>
                                    {byHour.map((hour) => (
                                        <Cell
                                            key={hour.hour}
                                            fill={hour.orders > 0 ? theme.revenue : idleBar}
                                        />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </ChartCard>

                    {/* ---------- POLA PER HARI ---------- */}
                    <ChartCard
                        title="Pola per Hari dalam Minggu"
                        subtitle="Dihitung per hari, bukan per minggu, supaya minggu dengan jumlah minggu berbeda tetap bisa dibandingkan."
                        icon={IconCalendar}
                        height={260}
                        footer={
                            busiestHours.length ? (
                                <span>
                                    Jam paling ramai: {busiestHours.map((hour) => hour.label).join(", ")}.
                                </span>
                            ) : null
                        }
                    >
                        <ResponsiveContainer width="100%" height={240}>
                            <BarChart data={byWeekday} margin={{ top: 8, right: 12, bottom: 4, left: 4 }}>
                                <Grid />
                                <MoneyAxis />
                                <XAxis
                                    dataKey="short"
                                    tick={{ fill: axis.tick, fontSize: axis.fontSize }}
                                    axisLine={false}
                                    tickLine={false}
                                />
                                <Tooltip content={<MoneyTooltip />} />
                                <Bar dataKey="revenue_per_day" name="Omzet rata-rata / hari" isAnimationActive={false} radius={[3, 3, 0, 0]} fill={theme.contribution} />
                            </BarChart>
                        </ResponsiveContainer>
                    </ChartCard>
                </div>

                <div className="grid gap-3 lg:grid-cols-2">
                    {/* ---------- PRODUK TERLARIS ---------- */}
                    <ChartCard
                        title="Produk Terlaris"
                        subtitle="Berdasarkan omzet, bukan jumlah terjual. Kalau memakai jumlah terjual, produk murah bisa mendominasi."
                        icon={IconChartBar}
                        height={300}
                    >
                        {topProducts.length ? (
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart
                                    data={topProducts}
                                    layout="vertical"
                                    margin={{ top: 4, right: 16, bottom: 4, left: 8 }}
                                >
                                    <Grid horizontal />
                                    <MoneyAxis />
                                    <XAxis
                                        type="number"
                                        tick={{ fill: axis.tick, fontSize: axis.fontSize }}
                                        axisLine={false}
                                        tickLine={false}
                                        tickFormatter={formatCompactCurrency}
                                    />
                                    <YAxis
                                        type="category"
                                        dataKey="title"
                                        tick={{ fill: axis.tick, fontSize: axis.fontSize }}
                                        axisLine={false}
                                        tickLine={false}
                                        width={130}
                                    />
                                    <Tooltip content={<MoneyTooltip />} />
                                    <Bar dataKey="revenue" name="Omzet" isAnimationActive={false} radius={[0, 3, 3, 0]} fill={theme.revenue} />
                                </BarChart>
                            </ResponsiveContainer>
                        ) : (
                            <EmptyPanel message="Belum ada produk terjual pada periode ini." />
                        )}
                    </ChartCard>

                    {/* ---------- MARGIN TERTINGGI ---------- */}
                    <ChartCard
                        title="Margin Tertinggi per Produk"
                        subtitle="Laba kotor per produk. Produk dengan omzet nol tidak di-ranking karena tidak punya margin untuk dinilai."
                        icon={IconPercentage}
                        height={300}
                    >
                        {productMargins.length ? (
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart
                                    data={productMargins}
                                    layout="vertical"
                                    margin={{ top: 4, right: 16, bottom: 4, left: 8 }}
                                >
                                    <Grid horizontal />
                                    <XAxis
                                        type="number"
                                        tick={{ fill: axis.tick, fontSize: axis.fontSize }}
                                        axisLine={false}
                                        tickLine={false}
                                        tickFormatter={(value) => `${value}%`}
                                    />
                                    <YAxis
                                        type="category"
                                        dataKey="title"
                                        tick={{ fill: axis.tick, fontSize: axis.fontSize }}
                                        axisLine={false}
                                        tickLine={false}
                                        width={130}
                                    />
                                    <Tooltip
                                        content={({ active, payload, label }) => (
                                            <PanelTooltip
                                                active={active}
                                                payload={payload}
                                                label={label}
                                                primary={(rows) => `Margin ${formatPercent(rows[0].value)}`}
                                                secondary={(rows) => `Laba ${formatCurrency(
                                                    productMargins.find((row) => row.title === label)
                                                        ?.margin_amount
                                                )}`}
                                            />
                                        )}
                                    />
                                    <Bar dataKey="margin_percent" name="Margin" isAnimationActive={false} radius={[0, 3, 3, 0]}>
                                        {productMargins.map((row) => (
                                            <Cell
                                                key={row.id ?? row.title}
                                                fill={row.margin_percent < 40 ? theme.danger : theme.contribution}
                                            />
                                        ))}
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        ) : (
                            <EmptyPanel message="Belum ada produk dengan omzet pada periode ini." />
                        )}
                    </ChartCard>
                </div>

                <div className="grid gap-3 lg:grid-cols-3">
                    {/* ---------- METODE PEMBAYARAN ---------- */}
                    <ChartCard
                        title="Metode Pembayaran"
                        subtitle="Untuk melihat komposisi kas antar metode pembayaran."
                        icon={IconChartPie}
                        height={260}
                    >
                        {byPaymentMethod.length ? (
                            <>
                                <ResponsiveContainer width="100%" height={200}>
                                    <PieChart>
                                        <Pie
                                            data={byPaymentMethod.map((row) => ({
                                                ...row,
                                                name: paymentLabel(row.method),
                                            }))}
                                            dataKey="revenue"
                                            nameKey="name"
                                            innerRadius={48}
                                            outerRadius={78}
                                            paddingAngle={2}
                                            stroke="none"
                                            isAnimationActive={false}
                                        >
                                            {byPaymentMethod.map((row, index) => (
                                                <Cell key={row.method} fill={paymentColor(index)} />
                                            ))}
                                        </Pie>
                                        <Tooltip
                                            content={({ active, payload }) => (
                                                <PanelTooltip
                                                    active={active}
                                                    payload={payload}
                                                    label={payload?.[0]?.name}
                                                    secondary={(rows) => formatCurrency(rows[0].value)}
                                                    primary={(rows) =>
                                                        `${formatNumber(rows[0].payload.orders)} transaksi`
                                                    }
                                                />
                                            )}
                                        />
                                    </PieChart>
                                </ResponsiveContainer>

                                <ul className="mt-1 space-y-1 px-2">
                                    {byPaymentMethod.map((row, index) => (
                                        <li key={row.method} className="flex items-center gap-2 text-xs">
                                            <span
                                                className="h-2 w-2 shrink-0 rounded-full"
                                                style={{ background: paymentColor(index) }}
                                            />
                                            <span className="text-slate-500 dark:text-slate-400">
                                                {paymentLabel(row.method)}
                                            </span>
                                            <span className="ml-auto text-slate-700 dark:text-slate-200">
                                                {formatCurrency(row.revenue)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        ) : (
                            <EmptyPanel message="Belum ada pembayaran pada periode ini." height={200} />
                        )}
                    </ChartCard>

                    {/* ---------- KATEGORI ---------- */}
                    <ChartCard
                        title="Omzet per Kategori"
                        subtitle="8 kategori teratas."
                        icon={IconChartDots}
                        height={260}
                    >
                        {categoryData.length ? (
                            <ResponsiveContainer width="100%" height={240}>
                                <BarChart
                                    data={categoryData}
                                    layout="vertical"
                                    margin={{ top: 4, right: 16, bottom: 4, left: 8 }}
                                >
                                    <Grid horizontal />
                                    <XAxis
                                        type="number"
                                        tick={{ fill: axis.tick, fontSize: axis.fontSize }}
                                        axisLine={false}
                                        tickLine={false}
                                        tickFormatter={formatCompactCurrency}
                                    />
                                    <YAxis
                                        type="category"
                                        dataKey="name"
                                        tick={{ fill: axis.tick, fontSize: axis.fontSize }}
                                        axisLine={false}
                                        tickLine={false}
                                        width={110}
                                    />
                                    <Tooltip content={<MoneyTooltip />} />
                                    <Bar dataKey="revenue" name="Omzet" isAnimationActive={false} radius={[0, 3, 3, 0]} fill={theme.gross} />
                                </BarChart>
                            </ResponsiveContainer>
                        ) : (
                            <EmptyPanel message="Belum ada penjualan berkategori." />
                        )}
                    </ChartCard>

                    {/* ---------- STOK MENIPIS ---------- */}
                    <ChartCard
                        title="Bahan Baku Menipis"
                        subtitle="Sudah menyentuh atau melewati batas minimum."
                        icon={IconAlertTriangle}
                        height={260}
                        footer={
                            <span>
                                Hanya bahan baku. Produk jadi tidak punya batas minimum, jadi tidak bisa
                                dianggap sebagai peringatan.
                            </span>
                        }
                    >
                        {lowStock.length ? (
                            <ul className="space-y-2 px-2">
                                {lowStock.map((item) => (
                                    <li key={item.id}>
                                        <div className="flex items-baseline justify-between gap-2 text-xs">
                                            <span className="truncate text-slate-700 dark:text-slate-300">
                                                {item.name}
                                            </span>
                                            <span className="shrink-0 text-slate-500 dark:text-slate-400">
                                                {formatNumber(item.stock)} / {formatNumber(item.min_stock)} {item.unit}
                                            </span>
                                        </div>
                                        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                                            <div
                                                className={`h-full rounded-full ${
                                                    item.ratio <= 0
                                                        ? "bg-rose-600 dark:bg-rose-500"
                                                        : item.ratio < 50
                                                          ? "bg-amber-500"
                                                          : "bg-cyan-600 dark:bg-cyan-500"
                                                }`}
                                                style={{ width: `${Math.max(2, Math.min(100, item.ratio))}%` }}
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <EmptyPanel
                                message="Semua bahan baku masih di atas batas minimum."
                                height={200}
                            />
                        )}
                    </ChartCard>
                </div>

                {/* ---------- PERFORMA KASIR ---------- */}
                <ChartCard
                    title="Performa per Kasir"
                    subtitle="Laba kontribusi, bukan laba bersih. Beban operasional tidak dibagi ke kasir karena tidak ada catatan yang bisa mengatribusikannya dengan adil."
                    icon={IconUsers}
                    height={300}
                >
                    {byCashier.length ? (
                        <div className="overflow-x-auto px-2">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 dark:border-slate-800">
                                        <th className="py-2 pr-4 font-medium">Kasir</th>
                                        <th className="py-2 pr-4 text-right font-medium">Transaksi</th>
                                        <th className="py-2 pr-4 text-right font-medium">Omzet Bersih</th>
                                        <th className="py-2 pr-4 text-right font-medium">Rata-rata</th>
                                        <th className="py-2 pr-4 text-right font-medium">HPP</th>
                                        <th className="py-2 pr-4 text-right font-medium">Laba Kontribusi</th>
                                        <th className="py-2 text-right font-medium">Margin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {byCashier.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-b border-slate-200/70 last:border-0 dark:border-slate-800/60"
                                        >
                                            <td className="py-2 pr-4 text-slate-800 dark:text-slate-200">
                                                {row.name}
                                            </td>
                                            <td className="py-2 pr-4 text-right text-slate-600 dark:text-slate-300">
                                                {formatNumber(row.orders)}
                                            </td>
                                            <td className="py-2 pr-4 text-right text-slate-800 dark:text-slate-200">
                                                {formatCurrency(row.net_revenue)}
                                            </td>
                                            <td className="py-2 pr-4 text-right text-slate-500 dark:text-slate-400">
                                                {formatCurrency(row.average_order)}
                                            </td>
                                            <td className="py-2 pr-4 text-right text-slate-500 dark:text-slate-400">
                                                {formatCurrency(row.hpp)}
                                            </td>
                                            <td className="py-2 pr-4 text-right text-emerald-700 dark:text-emerald-300">
                                                {formatCurrency(row.contribution_profit)}
                                            </td>
                                            <td className="py-2 text-right text-slate-600 dark:text-slate-300">
                                                {formatPercent(row.margin_percent)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <EmptyPanel message="Belum ada transaksi pada periode ini." />
                    )}
                </ChartCard>
                    </>
                )}
            </div>
        </DashboardLayout>
    );
}
