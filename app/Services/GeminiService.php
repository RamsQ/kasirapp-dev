<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct()
    {
        /**
         * Mengambil data dari config/services.php
         * Pastikan di .env Anda sudah set: 
         * GEMINI_MODEL=gemini-2.5-flash
         */
        $this->apiKey = config('services.gemini.key');
        $this->model  = config('services.gemini.model') ?? 'gemini-2.5-flash';
        
        /**
         * UPDATE: Menggunakan endpoint /v1/ karena model 2.5 
         * sudah berstatus stable di akun Anda.
         */
        $this->baseUrl = "https://generativelanguage.googleapis.com/v1/models";
    }

    /**
     * Mengirim permintaan ke Google Gemini API
     * * @param string $prompt
     * @return string
     */
    public function generateResponse(string $prompt)
    {
        if (!$this->apiKey) {
            return "Error: API Key tidak ditemukan. Periksa file .env dan config/services.php.";
        }

        try {
            // Membangun URL dengan model terbaru
            $url = "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}";

            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->timeout(30)->post($url, [
                "contents" => [
                    [
                        "parts" => [
                            ["text" => $prompt]
                        ]
                    ]
                ],
                "generationConfig" => [
                    "temperature" => 1, // Default untuk Gemini 2.5
                    "topP" => 0.95,
                    "maxOutputTokens" => 2000, // Kapasitas lebih besar untuk analisis mendalam
                ]
            ]);

            $result = $response->json();

            // Penanganan jika HTTP Request gagal
            if ($response->failed()) {
                $errorMsg = $result['error']['message'] ?? 'Unknown API Error';
                
                Log::error("Gemini API Error [" . $this->model . "]: " . $errorMsg);

                // Cek spesifik error kuota
                if (str_contains(strtolower($errorMsg), 'quota')) {
                    return "Maaf, kuota harian AI (Gemini 2.5) sudah mencapai batas. Silakan coba lagi nanti atau ganti model ke 1.5 di .env.";
                }

                // Cek jika model tidak ditemukan (404)
                if ($response->status() === 404) {
                    return "Model " . $this->model . " tidak ditemukan. Pastikan penulisan di .env benar (contoh: gemini-2.5-flash).";
                }

                return "Google API Error: " . $errorMsg;
            }

            // Ekstraksi jawaban teks
            return $result['candidates'][0]['content']['parts'][0]['text'] 
                ?? "AI berhasil terhubung namun tidak memberikan jawaban teks.";

        } catch (\Exception $e) {
            Log::error("Gemini Service Exception: " . $e->getMessage());
            return "Terjadi kendala koneksi ke server AI: " . $e->getMessage();
        }
    }
}