<?php

namespace App\Services\Ai;

use App\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Memilih provider AI aktif dan berpindah otomatis ke cadangan bila gagal.
 *
 * Kunci API tidak pernah dikembalikan ke frontend; hanya nama model yang
 * ditampilkan.
 */
class AiProviderFactory
{
    public const GEMINI = 'gemini';

    public const GROQ = 'groq';

    /**
     * Urutan fallback: provider utama dulu, lalu provider lain.
     *
     * @return array<int,string>
     */
    public function availableProviders(): array
    {
        $configured = config('services.ai.provider', self::GROQ);
        $others = array_values(array_filter([self::GROQ, self::GEMINI], fn ($p) => $p !== $configured));

        return array_merge([$configured], $others);
    }

    public function provider(): AiProvider
    {
        return $this->make($this->activeProviderName());
    }

    public function activeProviderName(): string
    {
        $configured = (string) config('services.ai.provider', self::GROQ);

        return in_array($configured, [self::GROQ, self::GEMINI], true) ? $configured : self::GROQ;
    }

    /**
     * True bila salah satu provider punya kunci. UI memakai ini agar tidak
     * menampilkan "belum dikonfigurasi" padahal ada provider yang siap.
     */
    public function anyConfigured(): bool
    {
        foreach ($this->availableProviders() as $name) {
            if ($this->make($name)->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    public function make(string $name): AiProvider
    {
        return match ($name) {
            self::GEMINI => app(GeminiProvider::class),
            default => app(GroqProvider::class),
        };
    }

    /**
     * Jalankan prompt memakai provider aktif; bila gagal, coba provider lain
     * yang kuncinya terisi. Ini menjaga AI tetap bisa dipakai saat kuota
     * provider utama habis.
     */
    public function generateResponse(string $prompt): array
    {
        $errors = [];

        foreach ($this->availableProviders() as $name) {
            $provider = $this->make($name);

            if (! $provider->isConfigured()) {
                $errors[] = $name.': belum dikonfigurasi';

                continue;
            }

            try {
                $reply = $provider->generateResponse($prompt);

                if ($name !== $this->activeProviderName()) {
                    Log::warning('AI provider utama gagal, memakai cadangan.', [
                        'fallback' => $name,
                        'errors' => $errors,
                    ]);
                }

                return ['reply' => $reply, 'provider' => $name, 'model' => $provider->model()];
            } catch (AiProviderException $exception) {
                $errors[] = $name.': '.$exception->getMessage();
                Log::warning('AI provider gagal, mencoba berikutnya.', [
                    'provider' => $name,
                    'status' => $exception->status,
                ]);
            } catch (Throwable $exception) {
                $errors[] = $name.': '.$exception::class;
            }
        }

        throw new AiProviderException(
            'Layanan AI sedang tidak tersedia. Silakan coba lagi sebentar lagi.',
            502
        );
    }
}
