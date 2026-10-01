/**
 * Membandingkan path Inertia dengan href Ziggy.
 *
 * `route()` dari Ziggy menghasilkan URL LENGKAP
 * (`http://127.0.0.1:8000/dashboard/products`), sedangkan `url` dari Inertia
 * cuma path (`/dashboard/products`). Kalau keduanya dibandingkan mentah,
 * tidak akan pernah sama dan halaman aktif tidak pernah tersorot.
 *
 * Awalnya tiap komponensidebar menulis versinya sendiri, dan tiga di
 * antaranya lupa membandingkan ulang — dampaknya menu aktif diam-diam mati.
 * Sekarang semuanya lewat sini supaya tidak bisa lagi melenceng sendiri.
 */

/**
 * Ubah href apa pun jadi path: buang origin, query string, dan slash akhir.
 *
 * @param  unknown  value
 */
export function toPath(value) {
    const raw = String(value ?? "").trim();

    if (raw === "") return "";

    let path = raw;

    if (/^https?:\/\//i.test(raw)) {
        try {
            path = new URL(raw).pathname;
        } catch {
            // URL rusak: biarkan nilainya dipakai apa adanya daripada
            // diam-diam jadi kosong dan membuat semua menu dianggap tidak
            // aktif.
        }
    }

    return path.split("?")[0].replace(/\/+$/, "");
}

/**
 * Apakah halaman sedang dibuka adalah halaman yang ditunjuk `href`?
 *
 * Cocok untuk yang punya anak juga: `/dashboard/products/1` tetap dianggap
 * sedang di `/dashboard/products`.
 *
 * @param  string  currentUrl  `url` dari `usePage()`
 * @param  unknown  href
 */
export function isActivePath(currentUrl, href) {
    const path = toPath(href);
    const current = toPath(currentUrl);

    if (path === "") return false;

    return current === path || current.startsWith(path + "/");
}
