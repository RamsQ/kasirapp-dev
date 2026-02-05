import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeSwitcherProvider } from './Context/ThemeSwitcherContext';
import { registerSW } from 'virtual:pwa-register';

// Registrasi Service Worker
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
    },
    progress: {
        color: '#4B5563',
    },
});
