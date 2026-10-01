<?php

namespace App\Services\Ai;

use App\Exceptions\AiProviderException;

interface AiProvider
{
    /**
     * Kirim prompt dan kembalikan jawaban sebagai teks.
     *
     * @throws AiProviderException
     */
    public function generateResponse(string $prompt): string;

    /** Nama provider untuk ditampilkan di UI, mis. "Groq". */
    public function label(): string;

    /** Model yang sedang dipakai. */
    public function model(): string;

    /** Apakah API key provider ini sudah dikonfigurasi. */
    public function isConfigured(): bool;
}
