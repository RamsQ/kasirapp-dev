import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.jsx',
            refresh: true,
        }),
        react(),
        VitePWA({
            registerType: 'autoUpdate',
            injectRegister: 'auto',
            includeAssets: ['favicon.ico', 'robots.txt', 'assets/icon/*.svg'],
            devOptions: {
                enabled: false, // Tetap false agar tidak error dev-sw.js saat coding
                type: 'module'
            },
            workbox: {
                navigateFallback: null,
                maximumFileSizeToCacheInBytes: 5 * 1024 * 1024,
                globPatterns: ['**/*.{js,css,html,ico,png,svg,json}'],
                
                runtimeCaching: [
                    {
                        // --- ATURAN UTAMA REALTIME ---
                        // Tambahkan path yang butuh data fresh di sini (api, transactions, queue, dll)
                        urlPattern: ({ url }) => 
                            url.pathname.startsWith('/api') || 
                            url.pathname.startsWith('/transactions') || 
                            url.pathname.includes('queue') ||
                            url.pathname.includes('check'),
                        handler: 'NetworkOnly', // WAJIB: Ambil langsung dari server, jangan lewat cache
                    },
                    {
                        // Halaman navigasi: Coba jaringan dulu (3 detik), baru pakai cache jika offline
                        urlPattern: ({ request }) => request.mode === 'navigate',
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'pages-cache',
                            networkTimeoutSeconds: 3,
                            expiration: {
                                maxEntries: 20,
                                maxAgeSeconds: 3600 // Hanya simpan 1 jam agar tidak basi
                            },
                        },
                    },
                    {
                        // Cache aset gambar (Statik tidak masalah)
                        urlPattern: /\.(?:png|jpg|jpeg|svg|gif|webp)$/,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'image-cache',
                            expiration: {
                                maxEntries: 100,
                                maxAgeSeconds: 30 * 24 * 60 * 60,
                            },
                        },
                    },
                ],
            },
            manifest: {
                name: 'Kasir PWA',
                short_name: 'Kasir',
                description: 'Aplikasi Kasir Digital PWA',
                theme_color: '#4B5563',
                background_color: '#f8fafc',
                display: 'standalone',
                orientation: 'any',
                scope: '/',
                start_url: '/',
                icons: [
                    {
                        src: '/assets/icon/logo-52.svg',
                        sizes: '52x52',
                        type: 'image/svg+xml'
                    },
                    {
                        src: '/assets/icon/Logo.svg',
                        sizes: '192x192',
                        type: 'image/svg+xml',
                        purpose: 'any maskable'
                    },
                    {
                        src: '/assets/icon/Logo.svg',
                        sizes: '512x512',
                        type: 'image/svg+xml'
                    }
                ]
            }
        })
    ],
    build: {
        chunkSizeWarningLimit: 1600,
        emptyOutDir: true,
    },
});