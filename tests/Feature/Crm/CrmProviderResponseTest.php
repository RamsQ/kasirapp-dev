<?php

namespace Tests\Feature\Crm;

use App\Models\ReportSetting;
use App\Services\CrmMessageSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * UAT: balasan Fonnte harus dibaca dengan benar.
 *
 * MASALAH ASAL
 *
 * Pengiriman WhatsApp yang BERHASIL dicatat `failed`.
 *
 * Fonnte membalas id pesan sebagai ARRAY berisi satu string:
 *
 *   {"detail":true,"id":["abc123"],"process":"pending","message":["success"]}
 *
 * Kode lama melakukan `(string) $payload['id']` langsung. Untuk array itu
 * PHP melempar "Array to string conversion", exception-nya ditangkap blok
 * catch, lalu dikembalikan sebagai `failed`. Padahal WhatsApp sudah
 * menerima pesannya. Operator melihat "gagal" untuk pesan yang benar-benar
 * terkirim, dan karena itu tidak akan pernah mengirim ulang.
 *
 * Dua masalah lain yang ikut terungkap di sini:
 *
 *  1. Fonnte membalas HTTP 200 juga saat MENOLAK pesan. Yang menentukan
 *     hasil adalah field `detail`, bukan status HTTP. Kalau hanya status
 *     HTTP yang diperiksa, penolakan akan tercatat `sent`.
 *
 *  2. Alasan penolakan ada di field `message` yang juga berupa array, dan
 *     tidak ikut ditampilkan -- jadi operator hanya melihat "provider
 *     menolak" tanpa ada yang bisa dikerjakannya.
 */
class CrmProviderResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function sender(): CrmMessageSender
    {
        ReportSetting::updateOrCreate(['id' => 1], ['wa_api_key' => 'token-uji']);

        return app(CrmMessageSender::class);
    }

    /**
     * Bentuk balasan sukses yang sebenarnya dikirim Fonnte: `id` berupa
     * array berisi satu string.
     */
    public function test_pesan_berhasil_dengan_id_berupa_array_tercatat_sebagai_sent(): void
    {
        Http::fake(['api.fonnte.com/send' => Http::response([
            'detail' => true,
            'id' => ['abc123'],
            'process' => 'pending',
            'message' => ['success'],
        ], 200)]);

        $result = $this->sender()->send('whatsapp', '6281234567890', 'Halo');

        $this->assertSame('sent', $result['status'],
            'Pesan yang terkirim harus tercatat sent, bukan failed.');
        $this->assertSame('abc123', $result['provider_reference'],
            'Id pesan harus diambil dari dalam array.');
        $this->assertNull($result['error']);
    }

    public function test_id_berupa_array_tidak_lagi_melempar_exception(): void
    {
        Http::fake(['api.fonnte.com/send' => Http::response([
            'detail' => true,
            'id' => ['abc123'],
            'message' => ['success'],
        ], 200)]);

        // Kalau masih ada "(string)" langsung ke array, PHP akan melempar
        // warning yang jadi ErrorException dan hasilnya `failed`. Test ini
        // gagal otomatis kalau casting itu dikembalikan.
        $result = $this->sender()->send('whatsapp', '6281234567890', 'Halo');

        $this->assertStringNotContainsString('Array to string conversion', (string) $result['error']);
        $this->assertNull($result['error']);
    }

    /**
     * Bentuk balasan ketika nomor tujuan tidak ada di perangkat.
     * Status HTTP tetap 200.
     */
    public function test_penolakan_dengan_status_200_tetap_tercatat_sebagai_gagal(): void
    {
        Http::fake(['api.fonnte.com/send' => Http::response([
            'detail' => false,
            'id' => [],
            'process' => 'none',
            'message' => ['token invalid'],
        ], 200)]);

        $result = $this->sender()->send('whatsapp', '6281234567890', 'Halo');

        $this->assertSame('failed', $result['status'],
            'Fonnte membalas 200 saat menolak. Kalau hanya status HTTP yang '
            .'dipakai, penolakan ini akan tercatat terkirim.');
    }

    public function test_alasan_penolakan_dari_provider_dikirim_ke_operator(): void
    {
        Http::fake(['api.fonnte.com/send' => Http::response([
            'detail' => false,
            'id' => [],
            'message' => ['token invalid'],
        ], 200)]);

        $result = $this->sender()->send('whatsapp', '6281234567890', 'Halo');

        $this->assertStringContainsString('token invalid', (string) $result['error'],
            'Operator harus melihat alasan sebenarnya dari provider, bukan hanya '
            .'"provider menolak" tanpa penjelasan.');
    }

    public function test_penolakan_dengan_status_http_kesalahan_juga_berjelas(): void
    {
        Http::fake(['api.fonnte.com/send' => Http::response([
            'message' => ['device not connected'],
        ], 401)]);

        $result = $this->sender()->send('whatsapp', '6281234567890', 'Halo');

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('device not connected', (string) $result['error']);
    }

    public function test_balasan_tanpa_id_tetap_tercatat_berhasil(): void
    {
        Http::fake(['api.fonnte.com/send' => Http::response([
            'detail' => true,
            'id' => [],
            'process' => 'pending',
            'message' => ['success'],
        ], 200)]);

        $result = $this->sender()->send('whatsapp', '6281234567890', 'Halo');

        $this->assertSame('sent', $result['status'],
            'Pesan tetap terkirim meski provider tidak mengembalikan id.');
        $this->assertNull($result['provider_reference']);
    }

    public function test_id_dalam_bentuk_data_akan_dipakai(): void
    {
        Http::fake(['api.fonnte.com/send' => Http::response([
            'data' => [['id' => 'def456']],
        ], 200)]);

        $result = $this->sender()->send('whatsapp', '6281234567890', 'Halo');

        $this->assertSame('sent', $result['status']);
        $this->assertSame('def456', $result['provider_reference']);
    }

    public function test_token_kosong_ditolak_sebelum_menepuk_provider(): void
    {
        Http::fake();

        ReportSetting::updateOrCreate(['id' => 1], ['wa_api_key' => null]);
        config(['services.crm.fonnte_token' => null]);

        $result = app(CrmMessageSender::class)->send('whatsapp', '6281234567890', 'Halo');

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('belum dikonfigurasi', (string) $result['error']);
        Http::assertNothingSent();
    }

    /**
     * Token harus dibaca lewat `config()`, bukan `env()` langsung.
     *
     * Kalau nanti ada `env('FONNTE_TOKEN')` dipanggil langsung di service,
     * test ini harus gagal. Kilasannya: setelah `php artisan config:cache`
     * dijalankan di server, `env()` di luar file config selalu null, jadi
     * semua pengiriman WhatsApp akan gagal padahal tokennya ada.
     */
    public function test_token_dibaca_lewat_config_bukan_env_polos(): void
    {
        $this->assertSame(
            config('services.crm.fonnte_token'),
            app(CrmMessageSender::class) instanceof CrmMessageSender
                ? config('services.crm.fonnte_token')
                : null,
            'Token Fonnte harus punya tempat di config/services.php.'
        );

        Http::fake(['api.fonnte.com/send' => Http::response([
            'detail' => true,
            'id' => ['abc123'],
        ], 200)]);

        config(['services.crm.fonnte_token' => 'token-dari-config']);
        ReportSetting::updateOrCreate(['id' => 1], ['wa_api_key' => null]);

        $result = app(CrmMessageSender::class)->send('whatsapp', '6281234567890', 'Halo');

        $this->assertSame('sent', $result['status'],
            'Token dari config harus dipakai kalau kolom pengaturan kosong.');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'token-dari-config'));
    }

    public function test_kolom_pengaturan_menang_atas_token_dari_env(): void
    {
        Http::fake(['api.fonnte.com/send' => Http::response([
            'detail' => true,
            'id' => ['abc123'],
        ], 200)]);

        config(['services.crm.fonnte_token' => 'token-dari-env']);
        ReportSetting::updateOrCreate(['id' => 1], ['wa_api_key' => 'token-dari-halaman']);

        app(CrmMessageSender::class)->send('whatsapp', '6281234567890', 'Halo');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'token-dari-halaman'));
    }
}
