import defaultTheme from "tailwindcss/defaultTheme";
import forms from "@tailwindcss/forms";

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        "./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php",
        "./storage/framework/views/*.php",
        "./resources/views/**/*.blade.php",
        "./resources/js/**/*.jsx",
    ],
    darkMode: "class",
    theme: {
        extend: {
            fontFamily: {
                sans: [
                    "Inter",
                    "Plus Jakarta Sans",
                    ...defaultTheme.fontFamily.sans,
                ],
                mono: [
                    "JetBrains Mono",
                    "Fira Code",
                    ...defaultTheme.fontFamily.mono,
                ],
            },
            colors: {
                // WARNA COSMIC UTAMA (Sekarang Biru Terang agar senada)
                "cosmic-main": "#818cf8",

                // PRIMARY INDIGO BLUE (Mengganti Rose/Pink)
                primary: {
                    50: "#eef2ff",
                    100: "#e0e7ff",
                    200: "#c7d2fe",
                    300: "#a5b4fc",
                    400: "#818cf8",
                    500: "#6366f1", // Warna Utama Aksen (Indigo 500)
                    600: "#4f46e5", // Warna Hover (Indigo 600)
                    700: "#4338ca",
                    800: "#3730a3",
                    900: "#312e81",
                    950: "#1e1b4b",
                },
                // SLATE DARK ELEGANT (Tetap Dipertahankan)
                slate: {
                    50: "#f8fafc",
                    100: "#f1f5f9",
                    200: "#e2e8f0",
                    300: "#cbd5e1",
                    400: "#94a3b8",
                    500: "#64748b",
                    600: "#475569",
                    700: "#334155",
                    800: "#1e293b", 
                    900: "#0f172a", // Sidebar / Card Background
                    950: "#020617", // Dasar Background Web
                },
                accent: {
                    50: "#ecfeff",
                    100: "#cffafe",
                    200: "#a5f3fc",
                    300: "#67e8f9",
                    400: "#22d3ee",
                    500: "#06b6d4",
                    600: "#0891b2",
                    700: "#0e7490",
                    800: "#155e75",
                    900: "#164e63",
                    950: "#083344",
                },
                success: {
                    50: "#ecfdf5",
                    100: "#d1fae5",
                    200: "#a7f3d0",
                    300: "#6ee7b7",
                    400: "#34d399",
                    500: "#10b981",
                    600: "#059669",
                    700: "#047857",
                    800: "#065f46",
                    900: "#064e3b",
                    950: "#022c22",
                },
                warning: {
                    50: "#fffbeb",
                    100: "#fef3c7",
                    200: "#fde68a",
                    300: "#fcd34d",
                    400: "#fbbf24",
                    500: "#f59e0b",
                    600: "#d97706",
                    700: "#b45309",
                    800: "#92400e",
                    900: "#78350f",
                    950: "#451a03",
                },
                danger: {
                    50: "#fff1f2",
                    100: "#ffe4e6",
                    200: "#fecdd3",
                    300: "#fda4af",
                    400: "#fb7185",
                    500: "#f43f5e",
                    600: "#e11d48",
                    700: "#be123c",
                    800: "#9f1239",
                    900: "#881337",
                    950: "#4c0519",
                },
            },
            // --- GRADASI COSMIC BLUE SPECTRUM ---
            backgroundImage: {
                'cosmic': 'linear-gradient(to right top, #312e81, #3730a3, #4338ca, #4f46e5, #6366f1, #818cf8, #93c5fd, #bfdbfe, #dbeafe, #f0f9ff)',
            },
            spacing: {
                18: "4.5rem",
                88: "22rem",
                100: "25rem",
                112: "28rem",
                128: "32rem",
            },
            minHeight: {
                touch: "2.75rem",
                "touch-lg": "3rem",
            },
            minWidth: {
                touch: "2.75rem",
                "touch-lg": "3rem",
            },
            borderRadius: {
                "4xl": "2rem",
            },
            boxShadow: {
                // Efek Glow diubah ke Biru/Indigo
                glow: "0 0 20px rgba(99, 102, 241, 0.3)",
                "glow-lg": "0 0 40px rgba(99, 102, 241, 0.4)",
                "glow-cosmic": "0 0 25px rgba(129, 140, 248, 0.4)", 
                "inner-lg": "inset 0 4px 6px -1px rgb(0 0 0 / 0.1)",
            },
            animation: {
                "slide-in": "slideIn 0.2s ease-out",
                "slide-up": "slideUp 0.2s ease-out",
                "fade-in": "fadeIn 0.15s ease-out",
                "pulse-subtle": "pulseSubtle 2s cubic-bezier(0.4, 0, 0.6, 1) infinite",
                "bounce-subtle": "bounceSubtle 0.5s cubic-bezier(0.34, 1.56, 0.64, 1)",
                "cart-add": "cartAdd 0.3s cubic-bezier(0.34, 1.56, 0.64, 1)",
            },
            keyframes: {
                slideIn: {
                    "0%": { transform: "translateX(100%)", opacity: "0" },
                    "100%": { transform: "translateX(0)", opacity: "1" },
                },
                slideUp: {
                    "0%": { transform: "translateY(10px)", opacity: "0" },
                    "100%": { transform: "translateY(0)", opacity: "1" },
                },
                fadeIn: {
                    "0%": { opacity: "0" },
                    "100%": { opacity: "1" },
                },
                pulseSubtle: {
                    "0%, 100%": { opacity: "1" },
                    "50%": { opacity: "0.7" },
                },
                bounceSubtle: {
                    "0%": { transform: "scale(1)" },
                    "50%": { transform: "scale(1.05)" },
                    "100%": { transform: "scale(1)" },
                },
                cartAdd: {
                    "0%": { transform: "scale(0.8)", opacity: "0" },
                    "50%": { transform: "scale(1.1)" },
                    "100%": { transform: "scale(1)", opacity: "1" },
                },
            },
            backdropBlur: {
                xs: "2px",
            },
            transitionDuration: {
                250: "250ms",
            },
        },
    },
    plugins: [forms],
};