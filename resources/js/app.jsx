import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeSwitcherProvider } from './Context/ThemeSwitcherContext';
import { registerSW } from 'virtual:pwa-register';
import { App as CapacitorApp } from '@capacitor/app'; // Import Capacitor App
import Swal from 'sweetalert2'; // Import SweetAlert2

// Registrasi Service Worker PWA
registerSW({ immediate: true });

const appName = import.meta.env.VITE_APP_NAME || 'My-kasir';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ThemeSwitcherProvider>
                <App {...props} />
            </ThemeSwitcherProvider>
        );

        // --- LOGIKA BACK BUTTON ANDROID (CAPACITOR) + SWEETALERT2 ---
        CapacitorApp.addListener('backButton', ({ canGoBack }) => {
            const path = window.location.pathname;

            // Jika berada di halaman utama (Login atau Dashboard)
            if (path === '/' || path === '/dashboard') {
                Swal.fire({
                    title: 'Keluar Aplikasi?',
                    text: 'Apakah Anda yakin ingin menutup aplikasi mangkujagad?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#4B5563', // Gray-600
                    cancelButtonColor: '#ef4444',  // Rose-500
                    confirmButtonText: 'Ya, Keluar',
                    cancelButtonText: 'Batal',
                    background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#fff',
                    color: document.documentElement.classList.contains('dark') ? '#fff' : '#000',
                }).then((result) => {
                    if (result.isConfirmed) {
                        CapacitorApp.exitApp();
                    }
                });
            } 
            // Jika sedang di halaman transaksi, arahkan kembali ke history
            else if (path.includes('/transactions')) {
                // Di kasir, biasanya kita ingin mematikan back agar tidak kehilangan input
                // Namun jika ingin tetap bisa kembali, gunakan window.history.back()
                window.history.back();
            }
            // Navigasi mundur normal di dalam aplikasi
            else if (canGoBack) {
                window.history.back();
            } 
            // Fallback jika stack kosong
            else {
                CapacitorApp.exitApp();
            }
        });
    },
    progress: {
        color: '#4B5563',
    },
});