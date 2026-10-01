<?php

namespace App\Services;

use App\Models\ReportSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class CrmMessageSender
{
    /**
     * @return array{status:string,provider_reference:?string,error:?string}
     */
    public function send(string $channel, string $target, string $message, ?string $subject = null): array
    {
        if ($channel === 'whatsapp') {
            return $this->sendWhatsapp($target, $message);
        }
        if ($channel === 'email') {
            return $this->sendEmail($target, $message, $subject);
        }

        return ['status' => 'preview', 'provider_reference' => null, 'error' => null];
    }

    /**
     * @return array{status:string,provider_reference:?string,error:?string}
     */
    private function sendWhatsapp(string $target, string $message): array
    {
        // Urutan sumber token:
        //   1. kolom di halaman Pengaturan -- placeholder yang diisi operator
        //   2. services.crm.fonnte_token -- dibaca dari .env lewat config
        //
        // Penting: token dari .env harus lewat `config()`, bukan `env()`
        // langsung. Nilai `env()` hanya tersedia selama config belum
        // di-cache; setelah `php artisan config:cache` dijalankan di server,
        // `env()` di luar file config selalu null dan setiap pengiriman
        // WhatsApp akan gagal dengan pesan "token belum dikonfigurasi"
        // padahal tokennya ada.
        $token = ReportSetting::first()?->wa_api_key
            ?: config('services.crm.fonnte_token');

        if (! $token) {
            return ['status' => 'failed', 'provider_reference' => null, 'error' => 'Token WhatsApp belum dikonfigurasi.'];
        }

        try {
            $response = Http::withHeaders(['Authorization' => $token])
                ->timeout(20)
                ->post('https://api.fonnte.com/send', [
                    'target' => $target,
                    'message' => $message,
                ]);

            if (! $response->successful()) {
                return [
                    'status' => 'failed',
                    'provider_reference' => null,
                    'error' => $this->describeRejection($response, 'Provider WhatsApp menolak pesannya.'),
                ];
            }

            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];

            /*
             * Fonnte membalas 200 OK juga ketika pesannya DITOLAK. Yang
             * menentukan hasil sebenarnya adalah field `detail`, bukan
             * status HTTP.
             *
             * Contoh penolakan yang tetap berstatus 200:
             *   {"detail":false,"id":[],"process":"none",
             *    "message":["token invalid"]}
             *
             * Kalau hanya status HTTP yang diperiksa, penolakan seperti ini
             * akan dicatat sebagai `sent` dan tidak akan pernah ketahuan.
             */
            if (array_key_exists('detail', $payload) && ! $payload['detail']) {
                return [
                    'status' => 'failed',
                    'provider_reference' => null,
                    'error' => $this->describeRejection($response, 'Provider WhatsApp menolak pesannya.'),
                ];
            }

            return [
                'status' => 'sent',
                'provider_reference' => $this->providerReference($payload),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'provider_reference' => null,
                'error' => Str::limit($exception->getMessage(), 500),
            ];
        }
    }

    /**
     * Ambil id pesan dari balasan Fonnte.
     *
     * BENTUKNYA TIDAK KONSISTEN, dan inilah yang pernah bikin pengiriman
     * yang berhasil tercatat `failed`:
     *
     *   sukses  : {"detail":true,"id":["abc123"],"process":"pending"}
     *   ditolak : {"detail":false,"id":[],"process":"none"}
     *   sebagian: {"data":[{"id":"def456"}]}
     *
     * Field `id` berupa ARRAY berisi satu string, bukan string biasa.
     * Melakukan `(string)` langsung ke situ menghasilkan
     * "Array to string conversion", exception itu ditangkap blok catch di
     * atas, dan hasilnya dikembalikan sebagai `failed` -- padahal WhatsApp
     * sudah menerima pesannya. Operator melihat "gagal" untuk pesan yang
     * benar-benar terkirim, dan karena itu tidak akan pernah mengirinya.
     *
     * Di sini nilai diambil dengan aman: array diambil elemen pertamanya,
     * dan apa pun yang bukan string atau angka dianggap tidak ada id.
     *
     * @param  array<mixed>  $payload
     */
    private function providerReference(array $payload): ?string
    {
        $candidates = [
            $payload['id'] ?? null,
            $payload['data'][0]['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate[0] ?? null;
            }

            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }

            if (is_int($candidate) || (is_string($candidate) && is_numeric($candidate))) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Ambil penjelasan penolakan dari balasan provider, kalau ada.
     *
     * Field `message` Fonnte juga berupa array, jadi pesannya perlu dirakit
     * dulu. Tanpa ini semua penolakan hanya tampil sebagai "provider
     * menolak" tanpa alasan yang bisa ditindaklanjuti.
     */
    private function describeRejection(Response $response, string $fallback): string
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        $parts = [];

        foreach (['message', 'error', 'detail'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_array($value)) {
                $value = implode('; ', array_filter(
                    array_map(fn ($item) => is_scalar($item) ? (string) $item : null, $value)
                ));
            }

            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }

        $reason = $parts === []
            ? $fallback
            : $fallback.' Provider bilang: '.implode(' | ', $parts);

        return Str::limit($reason, 500);
    }

    /**
     * @return array{status:string,provider_reference:?string,error:?string}
     */
    private function sendEmail(string $target, string $message, ?string $subject): array
    {
        try {
            Mail::raw($message, function ($mail) use ($target, $subject): void {
                $mail->to($target)->subject($subject ?: 'LakuPOS');
            });

            return ['status' => 'sent', 'provider_reference' => null, 'error' => null];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'provider_reference' => null,
                'error' => Str::limit($exception->getMessage(), 500),
            ];
        }
    }
}
