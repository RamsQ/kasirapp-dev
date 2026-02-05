import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Konfigurasi Axios
 * XMLHttpRequest header membantu Laravel mendeteksi request AJAX secara otomatis.
 */
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Konfigurasi Laravel Echo & Pusher
 * Menggunakan Private Channel memerlukan otorisasi (authEndpoint).
 */
window.Pusher = Pusher;

// Mengambil token CSRF dari elemen meta untuk otorisasi private channel
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
    
    // Endpoint internal Laravel untuk mengecek izin akses Private Channel
    authEndpoint: '/broadcasting/auth', 
    
    // Header tambahan untuk keamanan otorisasi
    auth: {
        headers: {
            'X-CSRF-Token': csrfToken,
            'Accept': 'application/json',
        }
    }
});

/**
 * Listener Error (Opsional - Sangat membantu saat Debugging)
 */
if (import.meta.env.DEV) {
    window.Pusher.logToConsole = true; // Matikan ini jika sudah naik ke produksi
}