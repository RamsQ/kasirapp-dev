import { useEffect, useState } from "react";

/**
 * Pantau aturan media CSS, dengan nilai yang sinkron dengan yang dihitung
 * browser dan bereaksiif.
 *
 * Kenapa bukan `window.innerWidth` langsung di dalam render?
 *
 * Nilai `window.innerWidth` hanya dibaca saat komponen me-render. Kalau
 * kasir memutar tablet dari vertikal ke horizontal, atau menarik jendela
 * browser di laptop, tidak ada yang memicu render ulang. Akibatnya kelas
 * yang dihitung JS (misalnya "keranjang tersembunyi") dan kelas yang
 * dihitung CSS (misalnya `lg:relative`) berakhir tidak sinkron: aside terlihat
 * melayang sebagai panel tetap sementara isinya disembunyikan, atau
 * sebaliknya. Perbaikannya bukan "panggil render ulang" tapi membaca nilai
 * yang memang berubah.
 *
 * Kenapa bukan hanya `matchMedia`?
 *
 * Karena perangkat ini dipakai di WebView tablet dan terminal POS yang
 * kadang tidak meneruskan event `change` dari MediaQueryList, meski
 * `matches`-nya sendiri sudah benar. Listener `resize` dipasang sebagai
 * jaring pengaman: cheap, dan menutup kasus itu. Event `change` tetap
 * dipakai sebagai jalur utama karena tidak dipanggil pada setiap pixel yang
 * digeser.
 *
 * @param  string  query  aturan media, mis. "(min-width: 1024px)"
 */
export default function useMediaQuery(query) {
    // Di server dan render pertama, selalu false supaya HTML dan hydrate
    // pertama cocok. Browser pasti mismatch kalau hydrate pertama tahu
    // lebar layar sementara server tidak.
    const read = () =>
        typeof window !== "undefined" && window.matchMedia
            ? window.matchMedia(query).matches
            : false;

    const [matches, setMatches] = useState(read);

    useEffect(() => {
        if (!window.matchMedia) return;

        const media = window.matchMedia(query);
        const handleChange = () => setMatches(media.matches);

        // Sinkronkan sekali lagi: antara render pertama dan effect ini,
        // kondisi bisa saja berubah (misalnya orang memutar tablet selagi
        // halaman masih memuat).
        setMatches(media.matches);

        const bind = () => {
            // addEventListener tidak ada di Safari versi lama.
            if (media.addEventListener) {
                media.addEventListener("change", handleChange);
            } else {
                media.addListener(handleChange);
            }
            window.addEventListener("resize", handleChange);
        };

        const unbind = () => {
            if (media.removeEventListener) {
                media.removeEventListener("change", handleChange);
            } else {
                media.removeListener(handleChange);
            }
            window.removeEventListener("resize", handleChange);
        };

        bind();

        return unbind;
    }, [query]);

    return matches;
}
