<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    {{--
        `maximum-scale=1, user-scalable=no` pernah dipakai supaya halaman POS
        tidak bisa di-cubit-cubit kasir sedang menekan. Efeknya: pengguna
        tidak punya jalan keluar. Di HP kecil, tabel lebar atau teks kecil
        yang tidak muat tidak bisa diperbesar — dan karena pinch-zoom adalah
        alat bantu terakhir untuk layanan yang tidak responsif, mematikan
        zoom justru menutup jalan keluar terakhir itu.

        `viewport-fit=cover` dipakai supaya notch dan garis gestur tidak
        menutupi isi di tepi layar.
    --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4B5563">

    <link rel="icon" type="image/png" href="{{ asset('laku-pos-logo.jpg') }}?v={{ time() }}">
    <link rel="apple-touch-icon" href="{{ asset('laku-pos-logo.jpg') }}">
    
    <link rel="manifest" href="/build/manifest.webmanifest">

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/build/sw.js');
            });
        }
    </script>
    
    <script>
        /*
         * Terapkan tema yang tersimpan SEBELUM halaman selesai dirender.
         *
         * Ditaruh di awal <head> supaya <html> sudah punya kelas dark
         * sebelum Tailwind dan React mulai menggambar. Kalau baru dipasang
         * setelah halaman selesai dimuat, halaman akan berkedip terang lebih
         * dulu setiap kali mode gelap dibuka.
         *
         * Hanya <html> yang disentuh di sini. Kelas pada <body> dipasang
         * oleh ThemeSwitcherContext, yang berjalan setelah React mount.
         * Kalau keduanya dipasang di dua tempat yang berbeda, <body> bisa
         * tertinggal pada kelas tema yang lama setiap kali pengguna mengganti
         * tema, dan aturan dark: milik Tailwind ikut salah karena mencocokkan
         * ke ancestor mana pun.
         */
        (function () {
            var darkMode = localStorage.getItem('darkMode') === 'true';
            var root = document.documentElement;

            root.classList.toggle('dark', darkMode);
            root.classList.toggle('light', !darkMode);
        })();
    </script>

    <title inertia>{{ config('app.name', 'Kasir PWA') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    @routes
    @viteReactRefresh
    @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
    @inertiaHead
    {{-- Latar halaman mengikuti tema. Hanya aturan html.dark yang dipakai. --}}
    <style>
        html.dark body { background-color: rgb(2 6 23); }
    </style>
</head>

<body class="font-sans antialiased bg-slate-50 transition-colors duration-200">
    @inertia
</body>

</html>
