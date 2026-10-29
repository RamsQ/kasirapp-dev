/**
 * Pembantu format dan warna untuk halaman Analitik.
 *
 * Ditarik ke file sendiri karena semua panel membutuhkannya. Kalau tiap
 * panel punya format sendiri, satu panel akan menampilkan "Rp 1.500.000"
 * dan panel di sebelahnya "1500000" dalam satu layar, dan yang salahnya
 * tidak akan pernah disadari kalau tidak dibandingkan.
 *
 * Warna grafik TIDAK ditulis sebagai kelas Tailwind, karena recharts
 * memberi warna itu ke elemen SVG, bukan ke elemen yang bisa diwarnai lewat
 * kelas `dark:`. Satu-satunya cara warna SVG ikut berubah tema adalah
 * menggambar ulang grafiknya dengan warna baru. Itu yang dilakukan
 * `useChartTheme()` di bawah.
 */

import { useCallback, useEffect, useRef, useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { useTheme } from "@/Context/ThemeSwitcherContext";

const currency = new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

const number = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 1 });

/** Rupiah penuh, untuk angka yang jadi perhatian utama. */
export const formatCurrency = (value) => currency.format(Number(value) || 0);

/**
 * Rupiah ringkas untuk sumbu Y.
 *
 * Sumbu Y tidak bisa menampilkan "Rp 12.500.000" di setiap tick, karena
 * labelnya saling menimpa. "12,5 jt" muat dan tetap bisa dibaca.
 */
export function formatCompactCurrency(value) {
    const amount = Number(value) || 0;
    const absolute = Math.abs(amount);
    const sign = amount < 0 ? "-" : "";

    if (absolute >= 1_000_000_000) {
        return `${sign}${number.format(absolute / 1_000_000_000)} M`;
    }

    if (absolute >= 1_000_000) {
        return `${sign}${number.format(absolute / 1_000_000)} jt`;
    }

    if (absolute >= 1_000) {
        return `${sign}${number.format(absolute / 1_000)} rb`;
    }

    return `${sign}${number.format(absolute)}`;
}

export const formatNumber = (value) => number.format(Number(value) || 0);

export const formatPercent = (value) => `${number.format(Number(value) || 0)}%`;

/**
 * Label perubahan dibanding periode sebelumnya.
 *
 * `null` berarti periode pembandingnya nol, jadi persentasenya memang tidak
 * bisa dihitung. Menampilkan "0%" di situation itu akan terbaca sebagai
 * "tidak berubah", padahal yang sebenarnya adalah "tidak ada pembanding".
 */
export function formatDelta(delta) {
    if (delta === null || delta === undefined) {
        return { text: "tidak ada pembanding", tone: "flat" };
    }

    const rounded = Number(delta);
    const arrow = rounded > 0 ? "▲" : rounded < 0 ? "▼" : "▬";
    const sign = rounded > 0 ? "+" : "";

    return {
        text: `${arrow} ${sign}${number.format(rounded)}%`,
        tone: rounded > 0 ? "up" : rounded < 0 ? "down" : "flat",
    };
}

/**
 * Warna teks delta, mengikuti tema.
 *
 * Hijau tua "#16a34a" di atas kartu putih jauh lebih terbaca daripada
 * emerald-400 yang menyilaukan, dan sebaliknya di tema gelap. Satu warna
 * untuk dua tema selalu salah di salah satunya.
 */
export function useDeltaColor() {
    const { darkMode } = useTheme();

    return {
        up: darkMode ? "#34d399" : "#047857",
        down: darkMode ? "#fb7185" : "#be123c",
        // slate-500 hanya 3.75:1 di atas kartu gelap -- di bawah ambang 4.5:1.
        flat: darkMode ? "#94a3b8" : "#64748b",
    };
}

/**
 * Warna dan arah "sehat" untuk sebuah metrik.
 *
 * HPP dan beban naik itu kabar buruk, sedangkan omzet dan laba naik itu
 * kabar baik. Tanpa ini, kartu HPP akan menampilkan hijau saat naik, dan
 * pemakai akan menyimpulkan toko sedang untung.
 */
const LOWER_IS_BETTER = new Set(["hpp", "app_expenses", "operating_expenses"]);

/**
 * Nada delta yang sudah memperhitungkan arah kesehatan metrik.
 */
export function deltaTone(key, delta) {
    if (delta === null || delta === undefined) return "flat";

    const value = Number(delta);
    if (value === 0) return "flat";

    const rising = value > 0;
    const healthy = LOWER_IS_BETTER.has(key) ? !rising : rising;

    return healthy ? "up" : "down";
}

/**
 * Palet seri.
 *
 * Dibuat per tema, bukan satu palet untuk dua-duanya. Warna cyan terang
 * (#22d3ee) hampir tidak terlihat di atas kartu putih, jadi di tema terang
 * dipakai versi yang lebih gelap. Grafik di tema terang yang pucat adalah
 * masalah yang jauh lebih buruk daripada kartu yang warnanya tidak konsisten.
 */
const SERIES_LIGHT = {
    revenue: "#0891b2",
    hpp: "#ea580c",
    gross: "#7c3aed",
    contribution: "#059669",
    operating: "#db2777",
    net: "#16a34a",
    grid: "#e2e8f0",
    axis: "#64748b",
    tooltipBorder: "#cbd5e1",
    tooltipBg: "#ffffff",
    bar: "#0891b2",
    barAlt: "#7c3aed",
    danger: "#dc2626",
};

const SERIES_DARK = {
    revenue: "#22d3ee",
    hpp: "#fb923c",
    gross: "#a78bfa",
    contribution: "#34d399",
    operating: "#f472b6",
    net: "#4ade80",
    grid: "#1e293b",
    axis: "#64748b",
    tooltipBorder: "#334155",
    tooltipBg: "#020617",
    bar: "#22d3ee",
    barAlt: "#a78bfa",
    danger: "#f87171",
};

/**
 * Warna-warna grafik untuk tema yang sedang aktif.
 *
 * Mengganti tema membuat komponen ini mengembalikan objek baru, dan karena
 * objeknya identitasnya berubah, recharts menggambar ulang grafiknya dengan
 * warna yang baru. Hook paling sederhana untuk membuat SVG yang di-recharts
 * benar-benar mengikuti tema.
 */
export function useChartTheme() {
    const { darkMode } = useTheme();
    return darkMode ? SERIES_DARK : SERIES_LIGHT;
}

/** Warna label sumbu dan garis grid untuk tema aktif. */
export function useChartAxis() {
    const { darkMode } = useTheme();

    return darkMode
        ? { tick: "#64748b", grid: "#1e293b", fontSize: 11 }
        : { tick: "#475569", grid: "#e2e8f0", fontSize: 11 };
}

/** Warna deret untuk metode pembayaran, tidak berubah antar tema. */
const PAYMENT_COLORS = [
    "#0891b2", "#7c3aed", "#059669", "#d97706",
    "#db2777", "#2563eb", "#ea580c", "#16a34a",
];

export const paymentColor = (index) => PAYMENT_COLORS[index % PAYMENT_COLORS.length];

/**
 * Label metode pembayaran yang sama dengan yang dipakai struk dan riwayat,
 * supaya kasir tidak perlu menerjemahkan "qris_manual" sendiri.
 */
export function paymentLabel(method) {
    const labels = {
        cash: "Tunai",
        qris_manual: "QRIS Manual",
        midtrans: "QRIS Midtrans",
        xendit: "QRIS Xendit",
        debit: "Kartu Debit",
        credit: "Kartu Kredit",
        transfer: "Transfer Bank",
    };

    return labels[method] || method || "Tidak diketahui";
}

/** Warna bar kosong (jam tanpa penjualan) sesuai tema. */
export function useIdleBarColor() {
    const { darkMode } = useTheme();
    return darkMode ? "#1e293b" : "#cbd5e1";
}

/**
 * Periode yang berubah terus selama warung buka.
 *
 * Untuk "Hari Ini", omzet bertambah setiap kali ada transaksi, jadi
 * auto-refresh berarti informasinya benar. Untuk "Tahun Ini", angka 5 menit
 * lalu hampir selalu sama dengan angka sekarang; reload terus-menerus cuma
 * membuang kerja database tanpa menambah informasi apa pun.
 */
const LIVE_PRESETS = new Set(["today"]);

/** Berapa detik antar reload otomatis. */
export const REFRESH_SECONDS = 60;

/**
 * Auto-refresh berkala untuk halaman Analitik.
 *
 * Memakai timer biasa, bukan channel Laravel Echo. Alasannya: reload dipicu
 * oleh waktu, bukan oleh transaksi. Kalau dipicu transaksi, tiap penjualan
 * di kasir akan menembak request yang di dalamnya ada sembilan query
 * agregasi, dan halaman ini bisa dibuka beberapa orang sekaligus. Dengan
 * timer, bebannya bisa diprediksi: satu request per menit per pengguna.
 *
 * Yang dijaga di sini:
 *
 *  1. Timer DILEWATI saat tab tidak terlihat. Laptop kasir yang ditinggal
 *     terbuka seharian tidak akan tetap meminta data setiap menit.
 *
 *  2. Timer ikut reset kalau props terbaru tiba. Kalau reload dipicu manual
 *     pada detik ke-59, timer lama akan langsung memuat ulang sekali lagi
 *     pada detik ke-60 dan angka melompat dua kali berturut-turut.
 *
 *  3. Tidak ada request kalau halaman sedang memuat. Inertia yang sedang
 *     mengambil data akan menimpa respons lama kalau dua request berlomba.
 *
 * @param  {string} preset  preset periode yang sedang aktif
 * @return {{enabled: boolean, toggle: () => void, lastLoadedAt: number|null, secondsLeft: number, refreshing: boolean}}
 */
export function useAutoRefresh(preset) {
    const page = usePage();
    const supportsLive = LIVE_PRESETS.has(preset);

    const [enabled, setEnabled] = useState(supportsLive);
    const [lastLoadedAt, setLastLoadedAt] = useState(() => Date.now());
    const [secondsLeft, setSecondsLeft] = useState(REFRESH_SECONDS);
    const [refreshing, setRefreshing] = useState(false);

    // Penanda waktu yang berubah setiap detik.
    //
    // "Diperbarui 42 detik lalu" dihitung dari Date.now() saat render. Tanpa
    // state ini, komponen hanya render ulang kalau props berubah, jadi
    // teksnya akan membeku di "baru saja" selama Auto dimatikan dan tidak
    // pernah menyebut umurnya. Orang lalu tidak tahu bahwa angka yang
    // dilihatnya sudah berjam-jam.
    const [, setTick] = useState(0);

    useEffect(() => {
        const id = setInterval(() => setTick((value) => value + 1), 1000);
        return () => clearInterval(id);
    }, []);

    const intervalRef = useRef(null);
    const countdownRef = useRef(null);

    // Flag request yang sedang berjalan.
    //
    // Sengaja ref, bukan state, karena setInterval membaca nilainya di luar
    // siklus render React. Dengan state, nilai di dalam closure akan tetap
    // nilai lama dan penjaga tidak akan pernah aktif.
    //
    // Versi sebelumnya membaca `page.props.loading`, yang tidak pernah ada:
    // Inertia tidak menaruh status request di props halaman. Akibatnya
    // penjaga ini selalu bernilai false, dan dua request masih bisa
    // bertumpuk kalau reload dipicu manual-tepat-detik.
    const inFlightRef = useRef(false);

    const reload = useCallback(() => {
        if (inFlightRef.current) return;

        inFlightRef.current = true;
        setRefreshing(true);

        router.reload({
            only: [
                "kpis", "deltas", "trend", "topProducts", "productMargins",
                "byCategory", "byPaymentMethod", "byHour", "byWeekday",
                "byCashier", "lowStock", "empty", "period",
            ],
            preserveState: true,
            preserveScroll: true,
            preserveView: true,
            onFinish: () => {
                inFlightRef.current = false;
                setRefreshing(false);
            },
        });
    }, []);

    // Timer utama.
    useEffect(() => {
        if (!enabled) {
            clearInterval(intervalRef.current);
            clearInterval(countdownRef.current);
            intervalRef.current = null;
            countdownRef.current = null;
            setSecondsLeft(REFRESH_SECONDS);
            return;
        }

        intervalRef.current = setInterval(() => {
            // Lewati kalau tab disembunyikan. Browser memperlambat
            // setInterval di tab latar, jadi hitungannya tidak bisa
            // dipercaya, dan orang yang kembali ke tab ini lalu menemukan
            // angka yang sudah lama.
            if (document.hidden) return;

            reload();
        }, REFRESH_SECONDS * 1000);

        // Hitung mundur supaya orang tahu angka berikutnya kapan masuk.
        countdownRef.current = setInterval(() => {
            setSecondsLeft((previous) => (previous <= 1 ? REFRESH_SECONDS : previous - 1));
        }, 1000);

        return () => {
            clearInterval(intervalRef.current);
            clearInterval(countdownRef.current);
        };
    }, [enabled, reload]);

    // Reset hitung mundur setiap kali props terbaru tiba. Tanpa ini,
    // reload manual pada detik ke-59 akan diikuti reload otomatis di detik
    // ke-60, dan angka terlihat melompat dua kali berturut-turut.
    //
    // `page.props` dijadikan dependensi, bukan nilainya, supaya efek ini
    // hanya jalan saat props benar-benar berganti.
    useEffect(() => {
        setLastLoadedAt(Date.now());
        setSecondsLeft(REFRESH_SECONDS);
    }, [page.props]);

    return {
        enabled,
        toggle: () => setEnabled((value) => !value),
        lastLoadedAt,
        secondsLeft,
        refreshing,
        canAutoRefresh: supportsLive,
        reloadNow: () => {
            setLastLoadedAt(Date.now());
            reload();
        },
    };
}

/**
 * "Diperbarui 42 detik lalu" dalam bahasa manusia.
 */
export function describeFreshness(lastLoadedAt) {
    if (!lastLoadedAt) return "";

    const seconds = Math.max(0, Math.round((Date.now() - lastLoadedAt) / 1000));

    if (seconds < 10) return "baru saja";
    if (seconds < 60) return `${seconds} detik lalu`;

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} menit lalu`;

    const hours = Math.floor(minutes / 60);
    return `${hours} jam lalu`;
}
