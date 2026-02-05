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
            // Pastikan build menyertakan aset manifest ke folder public
            includeAssets: ['favicon.ico', 'robots.txt', 'assets/icon/*.svg'],
            devOptions: {
                enabled: true,
                type: 'module'
            },
            workbox: {
                // 1. Matikan navigateFallback agar tidak bentrok dengan routing Laravel/Inertia
                navigateFallback: null,
                
                // 2. Tingkatkan limit ukuran file yang bisa di-cache (penting untuk app kasir)
                maximumFileSizeToCacheInBytes: 5 * 1024 * 1024, // 5MB

                // 3. Tentukan aset statis yang akan di-cache
                globPatterns: ['**/*.{js,css,html,ico,png,svg,json}'],
                
                // 4. Runtime Caching
                runtimeCaching: [
                    {
                        urlPattern: ({ request }) => request.mode === 'navigate',
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'pages-cache',
                            networkTimeoutSeconds: 3,
                            expiration: {
                                maxEntries: 50,
                                maxAgeSeconds: 24 * 60 * 60 // 1 hari
                            },
                        },
                    },
                    {
                        // Cache aset gambar secara agresif
                        urlPattern: /\.(?:png|jpg|jpeg|svg|gif|webp)$/,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'image-cache',
                            expiration: {
                                maxEntries: 100,
                                maxAgeSeconds: 30 * 24 * 60 * 60, // 30 hari
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
                orientation: 'any', // Memungkinkan rotasi layar di HP/Tablet
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
        // Pastikan output build bersih
        emptyOutDir: true,
    },
});