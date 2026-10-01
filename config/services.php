<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'xendit' => [
        'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Settlement
    |--------------------------------------------------------------------------
    |
    | Settlement gateway yang sudah melewati batas waktu tidak lagi di-retry
    | otomatis supaya tidak membuang kuota API. Statusnya menjadi `expired`
    | (bukan `failed`) karena uang belum tentu hilang: callback yang telat
    | tetap akan menyelesaikan transaksi seperti biasa.
    |
    */
    'settlement' => [
        'expire_after_minutes' => (int) env('SETTLEMENT_EXPIRE_AFTER_MINUTES', 1440),
        'max_attempts' => (int) env('SETTLEMENT_MAX_ATTEMPTS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Business Coach
    |--------------------------------------------------------------------------
    |
    | Provider aktif dipilih lewat AI_PROVIDER (`groq` atau `gemini`). Keduanya
    | punya free tier; Groq umumnya jauh lebih cepat. Bila provider utama
    | gagal atau kehabisan kuota, sistem otomatis memakai provider lain.
    |
    | Semua kunci API hanya dibaca dari .env, tidak pernah dikirim ke frontend.
    |
    */
    'ai' => [
        'provider' => env('AI_PROVIDER', 'groq'),
        'max_message_length' => (int) env('AI_MAX_MESSAGE_LENGTH', 1000),
        'requests_per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Groq AI Service
    |--------------------------------------------------------------------------
    |
    | API kompatibel OpenAI (chat/completions). Ambil free API key di
    | console.groq.com, lalu isi GROQ_API_KEY di .env.
    |
    | Daftar model gratis BISA BERUBAH dan berbeda per akun. Cek model yang
    | tersedia dengan:
    |   curl -H "Authorization: Bearer $GROQ_API_KEY" \
    |     https://api.groq.com/openai/v1/models
    |
    | Model di bawah sudah diverifikasi tersedia; ganti bila tidak.
    |
    */
    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'qwen/qwen3.8-27b'),
        'endpoint' => env('GROQ_API_ENDPOINT', 'https://api.groq.com/openai/v1/chat/completions'),
        'timeout' => (int) env('GROQ_TIMEOUT', 25),
        'max_tokens' => (int) env('GROQ_MAX_TOKENS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Gemini AI Service
    |--------------------------------------------------------------------------
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'endpoint' => env(
            'GEMINI_API_ENDPOINT',
            'https://generativelanguage.googleapis.com/v1beta/interactions'
        ),
        'timeout' => (int) env('GEMINI_TIMEOUT', 25),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | CRM
    |--------------------------------------------------------------------------
    |
    | Token Fonnte untuk pengiriman WhatsApp campaign.
    |
    | Dibaca lewat `config()`, bukan `env()` langsung di dalam service, supaya
    | tetap terbaca setelah `php artisan config:cache` dijalankan di server.
    | Nilai di sini bisa ditimpa dari halaman Pengaturan (kolom
    | `report_settings.wa_api_key`), yang diprioritaskan lebih dulu.
    |
    */
    'crm' => [
        'fonnte_token' => env('FONNTE_TOKEN'),
    ],

];
